<?php

namespace App\Command;

use App\Exception\InvalidArgumentException;
use App\Message\ProcessTransferMessage;
use App\Service\ConfigService;
use App\Service\MiraklClient;
use App\Service\PaymentSplitService;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class PaymentSplitCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'connector:dispatch:process-transfer';

    /**
     * @var MessageBusInterface
     */
    private $bus;

    /**
     * @var bool
     */
    private $enableProductPaymentSplit;

    /**
     * @var bool
     */
    private $enableServicePaymentSplit;

    /**
     * @var ConfigService
     */
    private $configService;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var PaymentSplitService
     */
    private $paymentSplitService;

    public function __construct(
        MessageBusInterface $bus,
        bool $enableProductPaymentSplit,
        bool $enableServicePaymentSplit,
        ConfigService $configService,
        MiraklClient $miraklClient,
        PaymentSplitService $paymentSplitService
    ) {
        $this->bus = $bus;
        $this->enableProductPaymentSplit = $enableProductPaymentSplit;
        $this->enableServicePaymentSplit = $enableServicePaymentSplit;
        $this->configService = $configService;
        $this->miraklClient = $miraklClient;
        $this->paymentSplitService = $paymentSplitService;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        if ($this->enableProductPaymentSplit) {
            $this->processBacklog(MiraklClient::ORDER_TYPE_PRODUCT);
            $this->processNewOrders(MiraklClient::ORDER_TYPE_PRODUCT);
        }

        if ($this->enableServicePaymentSplit) {
            $this->processBacklog(MiraklClient::ORDER_TYPE_SERVICE);
            $this->processNewOrders(MiraklClient::ORDER_TYPE_SERVICE);
        }

        return 0;
    }

    private function processBacklog(string $orderType)
    {
        $this->logger->info("Processing $orderType backlog.");
        $method = "getRetriable{$orderType}Transfers";
        $backlog = $this->paymentSplitService->$method();
        if (empty($backlog)) {
            $this->logger->info("No backlog.");
            return;
        }

        $chunks = array_chunk($backlog, 10, true);
        $method = "list{$orderType}OrdersById";
        foreach ($chunks as $chunk) {
            $this->dispatchTransfers(
                $this->paymentSplitService->updateTransfersFromOrders(
                    $chunk,
                    $this->miraklClient->$method(array_keys($chunk))
                )
            );
        }
    }

    private function processNewOrders(string $orderType)
    {
        $method = "get{$orderType}PaymentSplitCheckpoint";
        $checkpoint = $this->configService->$method() ?? '';

        $this->logger->info("Processing recent $orderType orders, checkpoint: $checkpoint.");
        $method = "list{$orderType}Orders";
        if ($checkpoint) {
            $method .= 'ByDate';
            $orders = $this->miraklClient->$method($checkpoint);
        } else {
            $orders = $this->miraklClient->$method();
        }

        if (empty($orders)) {
            $this->logger->info("No new $orderType order.");
            return;
        }

        $this->dispatchTransfers($this->paymentSplitService->getTransfersFromOrders($orders));

        $newCheckpoint = $this->updateCheckpoint($orders, $checkpoint);
        if ($checkpoint !== $newCheckpoint) {
            $method = "set{$orderType}PaymentSplitCheckpoint";
            $this->configService->$method($newCheckpoint);
            $this->logger->info("Setting new $orderType checkpoint:  . $newCheckpoint.");
        }
    }

    // Return the last creation date
    private function updateCheckpoint(array $orders, ?string $checkpoint): ?string
    {
        if (empty($orders)) {
            return $checkpoint;
        }

        $lastOrder = current(array_reverse($orders));
        return $lastOrder->getCreationDate();
    }

    private function dispatchTransfers(array $transfers): void
    {
        foreach ($transfers as $transfer) {
            if ($transfer->isDispatchable()) {
                $this->bus->dispatch(new ProcessTransferMessage(
                    $transfer->getId()
                ));
            }
        }
    }
}
