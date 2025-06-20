<?php

/**
 * @noinspection PhpUnused
 */

/*
 * Copyright (c) 2025 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace PayGate\PayWeb\Controller\Notify;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url;
use Magento\Framework\DB\Transaction;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Session\Generic;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Url\Helper\Data;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\Data\OrderStatusHistoryInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction\Builder;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\Controller\ResultFactory;
use Magento\Store\Model\StoreManagerInterface;
use PayGate\PayWeb\Controller\AbstractPaygate;
use PayGate\PayWeb\Model\Config as PayGateConfig;
use PayGate\PayWeb\Model\ConfigFactory;
use PayGate\PayWeb\Model\PayGate;
use Psr\Log\LoggerInterface;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\App\ResponseInterface;

class Index extends AbstractPaygate
{
    /**
     * @var Transaction
     */
    private Transaction $transactionModel;
    /**
     * @var ConfigFactory|PayGateConfig
     */
    private ConfigFactory|PayGateConfig $_paygateconfig;
    /**
     * @var string
     */
    private string $enableLogging;
    /**
     * @var EncryptorInterface
     */
    protected $encryptor;
    /**
     * @var OrderStatusHistoryRepositoryInterface
     */
    private OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository;
    /**
     * @var OrderStatusHistoryInterfaceFactory
     */
    private OrderStatusHistoryInterfaceFactory $historyFactory;

    /**
     * @param PageFactory $pageFactory
     * @param CustomerSession $customerSession
     * @param CheckoutSession $checkoutSession
     * @param OrderFactory $orderFactory
     * @param Generic $paygateSession
     * @param Data $urlHelper
     * @param Url $customerUrl
     * @param LoggerInterface $logger
     * @param TransactionFactory $transactionFactory
     * @param InvoiceService $invoiceService
     * @param InvoiceSender $invoiceSender
     * @param PayGate $paymentMethod
     * @param UrlInterface $urlBuilder
     * @param OrderRepositoryInterface $orderRepository
     * @param StoreManagerInterface $storeManager
     * @param OrderSender $orderSender
     * @param DateTime $date
     * @param CollectionFactory $orderCollectionFactory
     * @param Builder $_transactionBuilder
     * @param Transaction $transactionModel
     * @param ObjectManagerInterface $objectManager
     * @param Request $request
     * @param ManagerInterface $messageManager
     * @param ResultFactory $resultFactory
     * @param ResponseInterface $responseInterface
     * @param PayGateConfig $paygateconfig
     * @param EncryptorInterface $encryptor
     * @param OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository
     * @param OrderStatusHistoryInterfaceFactory $historyFactory
     */
    public function __construct(
        PageFactory $pageFactory,
        CustomerSession $customerSession,
        CheckoutSession $checkoutSession,
        OrderFactory $orderFactory,
        Generic $paygateSession,
        Data $urlHelper,
        Url $customerUrl,
        LoggerInterface $logger,
        TransactionFactory $transactionFactory,
        InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
        PayGate $paymentMethod,
        UrlInterface $urlBuilder,
        OrderRepositoryInterface $orderRepository,
        StoreManagerInterface $storeManager,
        OrderSender $orderSender,
        DateTime $date,
        CollectionFactory $orderCollectionFactory,
        Builder $_transactionBuilder,
        Transaction $transactionModel,
        ObjectManagerInterface $objectManager,
        Request $request,
        ManagerInterface $messageManager,
        ResultFactory $resultFactory,
        PayGateConfig $paygateconfig,
        EncryptorInterface $encryptor,
        OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository,
        OrderStatusHistoryInterfaceFactory $historyFactory
    ) {
        $this->transactionModel             = $transactionModel;
        $this->resultFactory                = $resultFactory;
        $this->_paygateconfig               = $paygateconfig;
        $this->enableLogging                = $this->_paygateconfig->getEnableLogging();
        $this->encryptor                    = $encryptor;
        $this->orderStatusHistoryRepository = $orderStatusHistoryRepository;
        $this->historyFactory               = $historyFactory;
        $this->orderSender = $orderSender;

        parent::__construct(
            $pageFactory,
            $customerSession,
            $checkoutSession,
            $orderFactory,
            $paygateSession,
            $urlHelper,
            $customerUrl,
            $logger,
            $transactionFactory,
            $invoiceService,
            $invoiceSender,
            $paymentMethod,
            $urlBuilder,
            $orderRepository,
            $storeManager,
            $orderSender,
            $date,
            $orderCollectionFactory,
            $_transactionBuilder,
            $objectManager,
            $request,
            $messageManager,
            $resultFactory,
            $encryptor,
            $orderStatusHistoryRepository,
            $historyFactory
        );
    }

    /**
     * Notify controller execution
     *
     * @noinspection PhpUndefinedMethodInspection
     */
    public function execute()
    {
        $pre = __METHOD__ . " : ";
        $this->_logger->debug($pre . 'bof');

        $errors = false;

        $notify_data = [];
        // Get notify data
        $paygate_data = $this->getPostData();

        if ($paygate_data === false) {
            $errors = true;
        }

        // Verify security signature
        $checkSumParams = '';
        if (!$errors) {
            foreach ($paygate_data as $key => $val) {
                $notify_data[$key] = $val;

                if ($key == 'PAYGATE_ID') {
                    $checkSumParams .= $val;
                    continue;
                }

                if ($key === 'AUTH_CODE') {
                    if ($val === 'null') {
                        $checkSumParams .= '';
                    } else {
                        $checkSumParams .= $val;
                    }
                    continue;
                }

                /**
                 * Re-check condition
                 *
                 * @noinspection PhpConditionAlreadyCheckedInspection
                 */
                if ($key != 'CHECKSUM' && $key != 'PAYGATE_ID' && $key !== 'AUTH_CODE') {
                    $checkSumParams .= $val;
                }

                if (empty($notify_data)) {
                    $errors = true;
                }
            }

            if ($this->enableLogging === '1') {
                $this->_logger->info('Reference @ Notify.php: ' . json_encode($notify_data['REFERENCE']));
                $this->_logger->info('Checksum @ Notify.php: ' . json_encode($notify_data['CHECKSUM']));
            }

            if ($this->getConfigData('test_mode') != '0') {
                $encryption_key = 'secret';
            } else {
                $encryption_key = $this->encryptor->decrypt($this->getConfigData('encryption_key'));
            }
            $checkSumParams .= $encryption_key;
        }

        // Verify security signature
        if (!$errors) {
            //@codingStandardsIgnoreStart
            $checkSumParams = md5($checkSumParams);
            //@codingStandardsIgnoreEnd
            if ($checkSumParams != $notify_data['CHECKSUM']) {
                $errors = true;
            }
        }

        $paygate_data['PAYMENT_TITLE'] = "PAYGATE_PAYWEB";

        if (!$errors && isset($paygate_data['TRANSACTION_STATUS']) && $this->_paymentMethod->getConfigData(
                'ipn_method'
            ) == '0'
        ) {
            // Prepare PayGate Data
            $status = filter_var($paygate_data['TRANSACTION_STATUS'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);

            $orderId = $this->request->getParam('eid');
            $order   = $this->orderRepository->get((int)$orderId);

            if ($order->getPaywebPaymentProcessed() == 1) {
                $this->_logger->debug('IPN ORDER ALREADY BEING PROCESSED');
            } else {
                $order->setPaywebPaymentProcessed(1)->save();
                $this->processOrder($order, $status, $paygate_data);
            }
        } else {
            $this->_logger->debug('IPN NOT START');
        }
        $this->_logger->debug($pre . 'eof');

        $resultRaw = $this->resultFactory->create(ResultFactory::TYPE_RAW);

        $resultRaw->setHttpResponseCode(200);

        $resultRaw->setContents('OK');

        return $resultRaw;
    }

    /**
     * Process order
     *
     * @param Order $order
     * @param int $status
     * @param array $paygate_data
     *
     * @return bool
     * @throws LocalizedException
     */
    public function processOrder(Order $order, int $status, array $paygate_data): bool
    {
        $success = false;

        switch ($status) {
            case 1:
                $orderState = $order->getState();
                if ($orderState != Order::STATE_COMPLETE && $orderState != Order::STATE_PROCESSING) {
                    $status = Order::STATE_PROCESSING;
                    $state  = Order::STATE_PROCESSING;

                    if ($this->getConfigData('successful_order_status') != "") {
                        $status = $this->getConfigData('successful_order_status');
                    }

                    if ($this->getConfigData('successful_order_state') != "") {
                        $state = $this->getConfigData('successful_order_state');
                    }

                    if ($this->_sendOrderEmail($order)) {
                        // Optionally, handle the comment logging for order notification
                    }

                    $invoice = $this->_createAndCaptureInvoice($order);

                    if ($this->_sendInvoiceEmail($invoice, $order)) {
                        // Optionally, handle the comment logging for invoice notification
                    }

                    $this->createTransaction($order, $paygate_data);
                    $order->setState($state)->setStatus($status);
                    $this->orderRepository->save($order);

                    if ($this->enableLogging === '1') {
                        $this->_logger->info('Order #' . $order->getId() . ' Saved @ Notify.php');
                    }

                    $success = true;
                }
                break;

            // no break
            case 0:
            default:
                $this->_checkoutSession->restoreQuote();
                $order->setPaywebPaymentProcessed(1)->save();
                // Save Transaction Response
                $this->createTransaction($order, $paygate_data);
                $order->cancel();
                $history = $order->addCommentToStatusHistory(
                    __(
                        'Notify Response, update order.'
                    )
                );
                $this->orderStatusHistoryRepository->save($history);
                $this->orderRepository->save($order);
                break;
        }

        return $success;
    }

    private function _sendOrderEmail(Order $order): bool
    {
        $order_successful_email = $this->_paymentMethod->getConfigData('order_email');
        if ($order_successful_email != '0') {
            // Add status history comment
            $history = $order->addCommentToStatusHistory(
                __('Notified customer about order #%1.', $order->getId())
            );
            $history->setIsCustomerNotified(true);

            try {
                // Save the status history
                $this->orderStatusHistoryRepository->save($history);

                // Save the order
                $this->orderRepository->save($order);
            } catch (LocalizedException $e) {
                // Handle any exceptions during the save process
                $this->_logger->error('Order save error: ' . $e->getMessage());
            }

            return true;
        }

        return false;
    }

    private function _createAndCaptureInvoice(Order $order)
    {
        $invoice = $this->_invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
        $invoice->register();

        // Save the invoice to the order
        $transaction = $this->transactionModel
            ->addObject($invoice)
            ->addObject($invoice->getOrder());

        $transaction->save();

        return $invoice;
    }

    private function _sendInvoiceEmail($invoice, Order $order): bool
    {
        $send_invoice_email = $this->_paymentMethod->getConfigData('invoice_email');
        if ($send_invoice_email != '0') {
            $this->invoiceSender->send($invoice);
            // Create a status history comment
            $history = $this->historyFactory->create()
                ->setStatus($order->getStatus())
                ->setEntityName('order')
                ->setComment(__('Notified customer about invoice #%1.', $invoice->getId()))
                ->setIsCustomerNotified(true);

            // Add the history to the order
            $order->addStatusHistory($history);

            // Save the order using the repository
            $this->orderRepository->save($order);

            return true;
        }

        return false;
    }


    // Retrieve post data

    /**
     * Returns processed, validated post data
     *
     * @return bool|array
     */
    public function getPostData(): bool|array
    {
        // Posted variables from ITN
        $nData = $this->request->getPostValue();

        // Strip any slashes in data
        foreach ($nData as $key => $val) {
            $nData[$key] = $val;
        }

        // Return "false" if no data was received
        if (empty($nData) || !isset($nData['CHECKSUM'])) {
            return (false);
        } else {
            return ($nData);
        }
    }

    /**
     * Saves invoice
     *
     * @throws LocalizedException
     */
    protected function saveInvoice()
    {
        // Check for mail msg
        $invoice = $this->_order->prepareInvoice();

        $invoice->register()->capture();

        /**
         * @var Transaction $transaction
         */
        $transaction = $this->_transactionFactory->create();
        $transaction->addObject($invoice)
            ->addObject($invoice->getOrder())
            ->save();

        // Add status history comment
        // Create new status history entry
        $statusHistory = $this->historyFactory->create();
        $statusHistory->setOrder($this->_order)
            ->setStatus($this->_order->getStatus())
            ->setEntityName(\Magento\Sales\Model\Order::ENTITY)
            ->setComment(__('Notified customer about invoice #%1.', $invoice->getIncrementId()))
            ->setIsCustomerNotified(true);

        // Save status history using repository
        $this->orderStatusHistoryRepository->save($statusHistory);

        // Save the order using the repository
        $this->orderRepository->save($this->_order);
    }
}
