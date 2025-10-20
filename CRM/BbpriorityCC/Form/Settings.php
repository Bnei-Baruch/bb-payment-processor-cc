<?php

use Civi\Api4\PaymentProcessorType;
use Civi\Api4\PaymentProcessor;

require_once 'CRM/Core/Form.php';

class CRM_BbpriorityCC_Form_Settings extends CRM_Core_Form {
  public function buildQuickForm() {
    $this->add('checkbox', 'ipn_http', 'Use http for IPN Callback');
    $this->add('text', 'merchant_terminal', 'Merchant Terminal', array('size' => 5));

    $paymentProcessors = $this->getPaymentProcessors();
    foreach( $paymentProcessors as $paymentProcessor ) {
      $settingCode = 'merchant_terminal_' . $paymentProcessor[ "id" ];
      $settingTitle = $paymentProcessor[ "name" ] . " (" .
        ( $paymentProcessor["is_test"] == 0 ? "Live" : "Test" ) . ")";
      $this->add('text', $settingCode, $settingTitle, array('size' => 5));
    }

    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => ts('Submit'),
        'isDefault' => TRUE,
      ),
    ));
    parent::buildQuickForm();
  }

  function setDefaultValues() {
    $defaults = array();
    $bbpriorityCC_settings = CRM_Core_BAO_Setting::getItem("BbpriorityCC Settings", 'bbpriorityCC_settings');
    if (!empty($bbpriorityCC_settings)) {
      $defaults = $bbpriorityCC_settings;
    }
    return $defaults;
  }

  public function postProcess() {
    $values = $this->exportValues();
    $bbpriorityCC_settings['ipn_http'] = $values['ipn_http'];
    $bbpriorityCC_settings['merchant_terminal'] = $values['merchant_terminal'];
    
    $paymentProcessors = $this->getPaymentProcessors();
    foreach( $paymentProcessors as $paymentProcessor ) {
      $settingId = 'merchant_terminal_' . $paymentProcessor[ "id" ];
      $bbpriorityCC_settings[$settingId] = $values[$settingId];
    }
    
    CRM_Core_BAO_Setting::setItem($bbpriorityCC_settings, "Bb Priority CC Settings", 'bbpriorityCC_settings');
    CRM_Core_Session::setStatus(
      ts('Bb Priority CC Settings Saved', array( 'domain' => 'info.kabbalah.payment.bbpriorityCC')),
      'Configuration Updated', 'success');

    parent::postProcess();
  }

  public function getPaymentProcessors() {
    // Get the BbpriorityCC payment processor type
    $paymentProcessorType = PaymentProcessorType::get(false)
      ->addWhere('name', '=', 'BbpriorityCC')
      ->execute()
      ->single();

    // Get the payment processors of bbpriorityCC type
    $paymentProcessors = PaymentProcessor::get(false)
      ->addWhere('payment_processor_type_id', '=', $paymentProcessorType['id'])
      ->addWhere('is_active', '=', 1)
      ->execute();

    return $paymentProcessors->getArrayCopy();
  }
}
