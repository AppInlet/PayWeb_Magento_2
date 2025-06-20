<?php
/**
 * @noinspection PhpPropertyOnlyWrittenInspection
 */

/*
 * Copyright (c) 2025 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace PayGate\PayWeb\Block\Payment;

use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Model\Config;
use PayGate\PayWeb\Model\InfoFactory;

/**
 * Paygate common payment info block
 * Uses default templates
 */
class Info extends \Magento\Payment\Block\Info
{
    /**
     * @var InfoFactory
     */
    protected InfoFactory $_PaygateInfoFactory;

    /**
     * @var Config
     */
    private Config $_paymentConfig;

    /**
     * @param Context $context
     * @param Config $paymentConfig
     * @param InfoFactory $PaygateInfoFactory
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $paymentConfig,
        InfoFactory $PaygateInfoFactory,
        array $data = []
    ) {
        $this->_PaygateInfoFactory = $PaygateInfoFactory;
        $this->_paymentConfig      = $paymentConfig;
        parent::__construct($context, $data);
    }
}
