<?php

require_once 'CRM/Core/Form.php';

class CRM_Bbprioritycash_Form_Settings extends CRM_Core_Form {
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
    $bbprioritycash_settings = CRM_Core_BAO_Setting::getItem("Bbprioritycash Settings", 'bbprioritycash_settings');
    if (!empty($bbprioritycash_settings)) {
      $defaults = $bbprioritycash_settings;
    }
    return $defaults;
  }

  public function postProcess() {
    $values = $this->exportValues();
    $bbprioritycash_settings['ipn_http'] = $values['ipn_http'];
    $bbprioritycash_settings['merchant_terminal'] = $values['merchant_terminal'];
    
    $paymentProcessors = $this->getPaymentProcessors();
    foreach( $paymentProcessors as $paymentProcessor ) {
      $settingId = 'merchant_terminal_' . $paymentProcessor[ "id" ];
      $bbprioritycash_settings[$settingId] = $values[$settingId];
    }
    
    CRM_Core_BAO_Setting::setItem($bbprioritycash_settings, "Bb Priority Cash Settings", 'bbprioritycash_settings');
    CRM_Core_Session::setStatus(
      ts('Bb Priority Cash Settings Saved', array( 'domain' => 'info.kabbalah.payment.bbprioritycash')),
      'Configuration Updated', 'success');

    parent::postProcess();
  }

  public function getPaymentProcessors() {
    // Get the bbprioritycash payment processor type
    $bbprioritycashName = array( 'name' => 'bbprioritycash' );
    $paymentProcessorType = civicrm_api3( 'PaymentProcessorType', 'getsingle', $bbprioritycashName );

    // Get the payment processors of bbprioritycash type
    $bbprioritycashType = array(
      'payment_processor_type_id' => $paymentProcessorType[ 'id' ],
      'is_active' => 1 );
    $paymentProcessors = civicrm_api3( 'PaymentProcessor', 'get', $bbprioritycashType );

    return $paymentProcessors["values"];
  }
}
