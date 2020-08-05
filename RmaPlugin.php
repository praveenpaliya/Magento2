<?php

declare(strict_types=1);

namespace BAT\Logistic\Plugin;

use BAT\Logistic\Model\Logistic;
use BAT\Logistic\Model\Mapper\Order as OrderMapper;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use BAT\Logistic\Model\LogisticFactory;
use BAT\Logistic\Helper\Data as LogisticHelper;
use BAT\Logistic\Logger\LogisticLogger;

class RmaPlugin
{
    /**
     * @var int
     */
    const ERROR_COUNTER_INITIAL_VALUE = 0;

    /**
     * @var LogisticFactory
     */
    private $modelLogisticFactory;

    /**
     * @var OrderMapper
     */
    private $orderMapper;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var LogisticHelper
     */
    private $logisticHelper;

    /**
     * @var LogisticLogger
     */
    private $logger;

    /**
     * Create constructor.
     * @param LogisticFactory $modelLogisticFactory
     * @param OrderMapper $orderMapper
     * @param StoreManagerInterface $storeManager
     * @param LogisticHelper $logisticHelper
     * @param LogisticLogger $logger
     */
    public function __construct(
        LogisticFactory $modelLogisticFactory,
        OrderMapper $orderMapper,
        StoreManagerInterface $storeManager,
        LogisticHelper $logisticHelper,
        LogisticLogger $logger
    )
    {
        $this->modelLogisticFactory = $modelLogisticFactory;
        $this->orderMapper = $orderMapper;
        $this->storeManager = $storeManager;
        $this->logisticHelper = $logisticHelper;
        $this->logger = $logger;
    }

    /**
     * @param \Magento\Rma\Model\Rma $subject
     * @param $result
     * @param $data
     */
    public function afterSaveRma(\Magento\Rma\Model\Rma $subject, $result, $data)
    {
        /** @var Order $salesOrder */
        $salesOrder = $result->getOrder();
        $returnOrderItems = $data['items'];

        if (false === $this->logisticHelper->getIsEnabled()) {
            return;
        }

        if (false == $this->logisticHelper->getIsOrdersEnabled($salesOrder->getStore()->getCode())) {
            return;
        }

        if (!empty($salesOrder->getAppliedRuleIds())) {
            $salesRuleIds = (array)explode(',', (string)$salesOrder->getAppliedRuleIds());
            if (in_array($this->logisticHelper->getSkipVoucherIds(), $salesRuleIds)) {
                $this->logger->info('Skip #' . $salesOrder->getIncrementId());
                return;
            }
        }

        $logistic = $this->modelLogisticFactory->create();
        $existing = $logistic
            ->getCollection()
            ->addFieldToFilter('sales_order_id', $salesOrder->getId())
            ->addFieldToFilter('type', 'returnOrder')
            ->getFirstItem();

        if ($existing->getId() === null) {
            $logistic
                ->setStoreId($this->storeManager->getStore()->getId())
                ->setSalesOrderId($salesOrder->getId())
                ->setStatus(Logistic::STATUS_PENDING)
                ->setType(Logistic::TYPE_RETURNORDER)
                ->setMode(Logistic::MODE_EXPORT)
                ->setCreated(new \DateTime)
                ->setUpdated(null)
                ->setErrorCounter(self::ERROR_COUNTER_INITIAL_VALUE)
                ->setPayload($this->orderMapper->mapReturnOrderToJson($salesOrder, $returnOrderItems));
        } else {
            $payload = $existing->getPayload();
        }

        $logistic->save();
        $this->logger->info('Created returnOrder item ' . $logistic->getId() . ' for order #' . $salesOrder->getIncrementId());
    }
}