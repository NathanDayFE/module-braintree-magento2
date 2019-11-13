<?php
declare(strict_types=1);

namespace Magento\Braintree\Model\Kount;

use Braintree\Transaction;
use Exception;
use Magento\Braintree\Model\Adapter\BraintreeAdapter;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\ResourceModel\Order\Invoice\Collection;
use Magento\Sales\Model\Service\CreditmemoService;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Class EnsConfig - Contains methods for dealing with Kount ENS Notifications.
 */
class EnsConfig
{
    const CONFIG_KOUNT_ID = 'payment/braintree/kount_id';
    const CONFIG_ALLOWED_IPS = 'payment/braintree/kount_allowed_ips';
    const CONFIG_ENVIRONMENT = 'payment/braintree/kount_environment';

    const RESPONSE_DECLINE = 'D';
    const RESPONSE_APPROVE = 'A';
    const RESPONSE_REVIEW = 'R';
    const RESPONSE_ESCALATE = 'E';

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;
    /**
     * @var OrderInterface
     */
    private $order;
    /**
     * @var BraintreeAdapter
     */
    private $braintreeAdapter;
    /**
     * @var TransactionFactory
     */
    private $transactionFactory;
    /**
     * @var CreditmemoFactory
     */
    private $creditmemoFactory;
    /**
     * @var CreditmemoService
     */
    private $creditmemoService;
    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param OrderInterface $order
     * @param BraintreeAdapter $braintreeAdapter
     * @param TransactionFactory $transactionFactory
     * @param CreditmemoFactory $creditmemoFactory
     * @param CreditmemoService $creditmemoService
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        OrderInterface $order,
        OrderRepositoryInterface $orderRepository,
        BraintreeAdapter $braintreeAdapter,
        TransactionFactory $transactionFactory,
        CreditmemoFactory $creditmemoFactory,
        CreditmemoService $creditmemoService
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->order = $order;
        $this->braintreeAdapter = $braintreeAdapter;
        $this->transactionFactory = $transactionFactory;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoService = $creditmemoService;
        $this->orderRepository = $orderRepository;
    }

    /**
     * @return array
     */
    public function getAllowedIps(): array
    {
        $ips = $this->scopeConfig->getValue(self::CONFIG_ALLOWED_IPS);
        return $ips ? explode(',', $ips) : [];
    }

    /**
     * @param $ip
     * @param $range
     * @return bool
     */
    private function isIpInRange($ip, $range): bool
    {
        if (strpos($range, '/') === false) {
            $range .= '/255';
        }
        // $range is in IP/CIDR format eg 127.0.0.1/255
        list($range, $netmask) = explode('/', $range, 2);

        $range_decimal = ip2long($range);
        $ip_decimal = ip2long($ip);
        $wildcard_decimal = (2 ** (32 - $netmask)) - 1;
        $netmask_decimal = ~ $wildcard_decimal;

        return (($ip_decimal & $netmask_decimal) === ($range_decimal & $netmask_decimal));
    }

    /**
     * @param $remoteAddress
     * @return bool
     */
    public function isAllowed($remoteAddress): bool
    {
        $allowedIps = $this->getAllowedIps();

        if (!$allowedIps) {
            return true;
        }

        foreach ($allowedIps as $allowedIp) {
            if ($this->isIpInRange($remoteAddress, $allowedIp)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    public function isSandbox(): bool
    {
        return $this->scopeConfig->getValue(self::CONFIG_ENVIRONMENT) === 'sandbox';
    }

    /**
     * @param $merchantId
     * @return bool
     */
    public function validateMerchantId($merchantId): bool
    {
        $stores = $this->storeManager->getStores();

        foreach ($stores as $store) {
            $storeMerchantId = $this->scopeConfig->getValue(
                self::CONFIG_KOUNT_ID,
                ScopeInterface::SCOPE_STORE,
                $store->getId()
            );

            return ((int) $storeMerchantId === $merchantId);
        }

        return false;
    }

    /**
     * @param $event
     * @return bool
     * @throws Exception
     */
    public function processEvent($event): bool
    {
        if ((string) $event->name === 'WORKFLOW_STATUS_EDIT') {
            return $this->workflowStatusEdit($event);
        }

        return false;
    }

    /**
     * @param $event
     * @return bool
     * @throws Exception
     */
    public function workflowStatusEdit($event): bool
    {
        $incrementId = $this->getIncrementId($event);
        $kountTransactionId = $this->getKountTransactionId($event);

        if ($incrementId && $kountTransactionId) {
            /** @var Order $order */
            $order = $this->order->loadByIncrementId($incrementId);

            if ($order) {
                /** @var Payment $payment */
                $payment = $order->getPayment();
                $paymentKountId = $payment->getAdditionalInformation('riskDataId');

                if ($kountTransactionId === $paymentKountId) {
                    if ((string) $event->old_value === self::RESPONSE_REVIEW ||
                        (string) $event->old_value === self::RESPONSE_ESCALATE
                    ) {
                        if ((string) $event->new_value === self::RESPONSE_APPROVE) {
                            return $this->approveOrder($order);
                        }

                        if ((string) $event->new_value === self::RESPONSE_DECLINE) {
                            return $this->declineOrder($order);
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param $event
     * @return int|null
     */
    public function getIncrementId($event)
    {
        if (isset($event->key['order_number'])) {
            return (int) $event->key['order_number'];
        }

        return null;
    }

    /**
     * @param $event
     * @return string|null
     */
    public function getKountTransactionId($event)
    {
        if (isset($event->key)) {
            return (string) $event->key;
        }

        return null;
    }

    /**
     * @param OrderInterface $order
     * @return bool
     * @throws Exception
     */
    private function approveOrder(OrderInterface $order): bool
    {
        /** @var Order $order */
        if ($order->getStatus() === Order::STATUS_FRAUD || $order->getStatus() === Order::STATE_PAYMENT_REVIEW) {
            $order->getPayment()->accept();
            $this->orderRepository->save($order);
            return true;
        }

        return false;
    }

    /**
     * @param OrderInterface $order
     * @return bool
     * @throws Exception
     */
    private function declineOrder(OrderInterface $order): bool
    {
        /** @var Order $order */
        if ($order->getStatus() === Order::STATUS_FRAUD || $order->getStatus() === Order::STATE_PAYMENT_REVIEW) {
            $braintreeId = $order->getPayment()->getCcTransId();

            /** @var Transaction $braintreeTransaction */
            $braintreeTransaction = $this->braintreeAdapter->findById($braintreeId);

            if ($braintreeTransaction) {
                if ($braintreeTransaction->status === Transaction::AUTHORIZED
                    || $braintreeTransaction->status === Transaction::SUBMITTED_FOR_SETTLEMENT) {
                    return $this->voidOrder($order);
                }

                if ($braintreeTransaction->status === Transaction::SETTLED) {
                    return $this->refundOrder($order);
                }
            }
        }

        return false;
    }

    /**
     * @param OrderInterface $order
     * @return bool
     * @throws Exception
     */
    private function voidOrder(OrderInterface $order): bool
    {
        /** @var Collection $invoices */
        $invoices = $order->getInvoiceCollection();

        if (count($invoices->getItems()) > 0) {
            /** @var Order $order */
            foreach ($invoices as $invoice) {
                /** @var Invoice $invoice */
                $invoice->void();
                $invoice->getOrder()->setStatus(Order::STATE_CANCELED);
                $invoice->getOrder()->addCommentToStatusHistory(
                    'Order declined through Kount, order voided in Magento.'
                );

                $this->transactionFactory->create()
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder())
                    ->save();

            }
            return true;
        } else {
            $order->getPayment()->deny();
            $this->orderRepository->save($order);
            return true;
        }

        return false;
    }

    /**
     * @param OrderInterface $order
     * @return bool
     * @throws LocalizedException
     */
    private function refundOrder(OrderInterface $order): bool
    {
        /** @var Order $order */
        foreach ($order->getInvoiceCollection() as $invoice) {
            /** @var Invoice $invoice */
            if ($invoice->getState() !== Order\Invoice::STATE_PAID) {
                $invoice->pay();
            }

            if ($invoice->canRefund()) {
                $creditMemo = $this->creditmemoFactory->createByInvoice($invoice);
                $creditMemo->setInvoice($invoice);
                $this->creditmemoService->refund($creditMemo);

                return true;
            }

            return false;
        }

        return false;
    }
}
