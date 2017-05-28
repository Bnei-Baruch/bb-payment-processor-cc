<?php

/**
 *
 * @package BBPriorityCash [after Dummy Payment Processor]
 * @author Gregory Shilin <gshilin@gmail.com>
 */

require_once 'CRM/Core/Payment.php';
require_once 'includes/PelecardAPI.php';

/**
 * BBPriorityCash payment processor
 */
class CRM_Core_Payment_BBPriorityCash extends CRM_Core_Payment {
  CONST BBPriority_CURRENCY_NIS = 1;
  /**
   * mode of operation: live or test
   *
   * @var object
   */
  protected $_mode = NULL;
  protected $_params = array();
  protected $_doDirectPaymentResult = array();

  /**
   * Set result from do Direct Payment for test purposes.
   *
   * @param array $doDirectPaymentResult
   *  Result to be returned from test.
   */
  public function setDoDirectPaymentResult($doDirectPaymentResult) {
    $this->_doDirectPaymentResult = $doDirectPaymentResult;
    if (empty($this->_doDirectPaymentResult['trxn_id'])) {
      $this->_doDirectPaymentResult['trxn_id'] = array();
    } else {
      $this->_doDirectPaymentResult['trxn_id'] = (array)$doDirectPaymentResult['trxn_id'];
    }
  }

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = NULL;
  /**
   * Payment Type Processor Name
   *
   * @var string
   */
  protected $_processorName = null;

  /**
   * Constructor.
   *
   * @param string $mode
   *   The mode of operation: live or test.
   *
   * @param $paymentProcessor
   *
   */
  public function __construct($mode, &$paymentProcessor) {
    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = 'BB Payment Cash';
  }

  /**
   * Singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return object
   * @static
   *
   */
  static function &singleton($mode, &$paymentProcessor) {
    $processorName = $paymentProcessor["name"];
    if (self::$_singleton[$processorName] === NULL) {
      self::$_singleton[$processorName] = new self($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
  }

  /**
   * Submit a payment using Advanced Integration Method.
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   *
   * @return array
   *   the result in a nice formatted array (or an error object)
   */
  public function doDirectPayment(&$params) {

    if (!empty($this->_doDirectPaymentResult)) {
      $result = $this->_doDirectPaymentResult;
      $result['trxn_id'] = array_shift($this->_doDirectPaymentResult['trxn_id']);
      return $result;
    }
    if ($this->_mode == 'test') {
      $query = "SELECT MAX(trxn_id) FROM civicrm_contribution WHERE trxn_id LIKE 'test\\_%'";
      $p = array();
      $trxn_id = strval(CRM_Core_Dao::singleValueQuery($query, $p));
      $trxn_id = str_replace('test_', '', $trxn_id);
      $trxn_id = intval($trxn_id) + 1;
      $params['trxn_id'] = 'test_' . $trxn_id . '_' . uniqid();
    } else {
      $query = "SELECT MAX(trxn_id) FROM civicrm_contribution WHERE trxn_id LIKE 'live_%'";
      $p = array();
      $trxn_id = strval(CRM_Core_Dao::singleValueQuery($query, $p));
      $trxn_id = str_replace('live_', '', $trxn_id);
      $trxn_id = intval($trxn_id) + 1;
      $params['trxn_id'] = 'live_' . $trxn_id . '_' . uniqid();
    }
    $params['gross_amount'] = $params['amount'];
    // Add a fee_amount so we can make sure fees are handled properly in underlying classes.
    $params['fee_amount'] = 1.50;
    $params['net_amount'] = $params['gross_amount'] - $params['fee_amount'];

    return $params;
  }

  /**
   * Are back office payments supported.
   *
   * E.g paypal standard won't permit you to enter a credit card associated with someone else's login.
   *
   * @return bool
   */
  protected function supportsLiveMode() {
    return TRUE;
  }

  /**
   * Generate error object.
   *
   * Throwing exceptions is preferred over this.
   *
   * @param string $errorCode
   * @param string $errorMessage
   *
   * @return CRM_Core_Error
   *   Error object.
   */
  public function &error($errorCode = NULL, $errorMessage = NULL) {
    $e = CRM_Core_Error::singleton();
    if ($errorCode) {
      $e->push($errorCode, 0, NULL, $errorMessage);
    } else {
      $e->push(9001, 0, NULL, 'Unknown System Error.');
    }
    return $e;
  }

  /**
   * This function checks to see if we have the right config values.
   *
   * @return string
   *   the error message if any
   */
  public function checkConfig() {
    $config = CRM_Core_Config::singleton();
    $error = array();

    if (empty($this->_paymentProcessor["user_name"])) {
      $error[] = ts("Merchant Name is not set in the BBP Payment Processor settings.");
    }
    if (empty($this->_paymentProcessor["password"])) {
      $error[] = ts("Merchant Password is not set in the BBP Payment Processor settings.");
    }

    if (!empty($error)) {
      return implode("<p>", $error);
    } else {
      return NULL;
    }
  }

  /**
   * Get an array of the fields that can be edited on the recurring contribution.
   *
   * Some payment processors support editing the amount and other scheduling details of recurring payments, especially
   * those which use tokens. Others are fixed. This function allows the processor to return an array of the fields that
   * can be updated from the contribution recur edit screen.
   *
   * The fields are likely to be a subset of these
   *  - 'amount',
   *  - 'installments',
   *  - 'frequency_interval',
   *  - 'frequency_unit',
   *  - 'cycle_day',
   *  - 'next_sched_contribution_date',
   *  - 'end_date',
   *  - 'failure_retry_day',
   *
   * The form does not restrict which fields from the contribution_recur table can be added (although if the html_type
   * metadata is not defined in the xml for the field it will cause an error.
   *
   * Open question - would it make sense to return membership_id in this - which is sometimes editable and is on that
   * form (UpdateSubscription).
   *
   * @return array
   */
  public function getEditableRecurringScheduleFields() {
    return array('amount', 'next_sched_contribution_date');
  }

  function doTransferCheckout(&$params, $component = 'contribute') {

    $config = CRM_Core_Config::singleton();

    if ($component != 'contribute' && $component != 'event') {
      CRM_Core_Error::fatal(ts('Component is invalid'));
    }

    if (array_key_exists('webform_redirect_success', $params)) {
      $returnURL = $params['webform_redirect_success'];
      $cancelURL = $params['webform_redirect_cancel'];
    } else {
      $url = ($component == 'event') ? 'civicrm/event/register' : 'civicrm/contribute/transact';
      $cancel = ($component == 'event') ? '_qf_Register_display' : '_qf_Main_display';
      $returnURL = CRM_Utils_System::url($url,
        "_qf_ThankYou_display=1&qfKey={$params['qfKey']}",
        TRUE, NULL, FALSE
      );

      $cancelUrlString = "$cancel=1&cancel=1&qfKey={$params['qfKey']}";
      if (CRM_Utils_Array::value('is_recur', $params)) {
        $cancelUrlString .= "&isRecur=1&recurId={$params['contributionRecurID']}&contribId={$params['contributionID']}";
      }

      $cancelURL = CRM_Utils_System::url(
        $url,
        $cancelUrlString,
        TRUE, NULL, FALSE
      );
    }

    $merchantUrlParams = "contactID={$params['contactID']}&contributionID={$params['contributionID']}";
    if ($component == 'event') {
      $merchantUrlParams .= "&eventID={$params['eventID']}&participantID={$params['participantID']}";
    } else {
      $membershipID = CRM_Utils_Array::value('membershipID', $params);
      if ($membershipID) {
        $merchantUrlParams .= "&membershipID=$membershipID";
      }
      $contributionPageID = CRM_Utils_Array::value('contributionPageID', $params);
      if ($contributionPageID) {
        $merchantUrlParams .= "&contributionPageID=$contributionPageID";
      }
      $relatedContactID = CRM_Utils_Array::value('related_contact', $params);
      if ($relatedContactID) {
        $merchantUrlParams .= "&relatedContactID=$relatedContactID";

        $onBehalfDupeAlert = CRM_Utils_Array::value('onbehalf_dupe_alert', $params);
        if ($onBehalfDupeAlert) {
          $merchantUrlParams .= "&onBehalfDupeAlert=$onBehalfDupeAlert";
        }
      }
    }
    $merchantUrl = $config->userFrameworkBaseURL . 'civicrm/payment/ipn?processor_name=BBPriorityCash&mode=' . $this->_mode
      . '&md=' . $component . '&qfKey=' . $params["qfKey"] . '&' . $merchantUrlParams;

    $miObj = new PelecardAPI;
    $miObj->setParameter("user", $this->_paymentProcessor["user_name"]);
    $miObj->setParameter("password", $this->_paymentProcessor["password"]);
    $miObj->setParameter("terminal", $this->_paymentProcessor["signature"]);
    $miObj->setParameter("LogoUrl", $this->_paymentProcessor["url_site"]);

    $miObj->setParameter("UserKey", $params['qfKey']);

    $miObj->setParameter("GoodUrl", $returnURL);
    $miObj->setParameter("ErrorUrl", $cancelURL);
    $miObj->setParameter("CancelUrl", $cancelURL);
    $miObj->setParameter("Total", $params["amount"] * 100);
    $miObj->setParameter("Currency", self::BBPriority_CURRENCY_NIS); // ZZZ
    $miObj->setParameter("MinPayments", 1);
    $miObj->setParameter("MaxPayments", 1); // ZZZ

    global $language;
    $lang = strtoupper($language->language);
    if ($lang == 'HE') {
      $miObj->setParameter("TopText", 'BB כרטיסי אשראי');
      $miObj->setParameter("BottomText", '© בני ברוך קבלה לעם');
      $miObj->setParameter("Language", 'HE');
    } elseif ($lang == 'RU') {
      $miObj->setParameter("TopText", 'BB Кредитные Карты');
      $miObj->setParameter("BottomText", '© Бней Барух Каббала лаАм');
      $miObj->setParameter("Language", 'RU');
    } else {
      $miObj->setParameter("TopText", 'BB Credit Cards');
      $miObj->setParameter("BottomText", '© Bnei Baruch Kabbalah laAm');
      $miObj->setParameter("Language", 'EN');
    }

    $result = $miObj->getRedirectUrl();
    $error = $result[0];
    if ($error > 0) {
      $message = $result[1];
      printf("Error[%s]: %s\n", $error, $message);
      exit(1);
    } else {
      $url = $result[1];
    }

    // Print the tpl to redirect to Pelecard
    $template = CRM_Core_Smarty::singleton();
    $template->assign('url', $url);
    print $template->fetch('CRM/Core/Payment/Bbprioritycash.tpl');

    CRM_Utils_System::civiExit();
  }

  public function handlePaymentNotification() {
    $input = $ids = $objects = array();
    $ipn = new CRM_Core_Payment_bbprioritycashIPN();

    // load vars in $input, &ids
    $ipn->getInput($input, $ids);
    CRM_Core_Error::debug_log_message("bbprioritycash IPN Response: Parameteres received \n input: " . print_r($input, TRUE) . "\n ids: " . print_r($ids, TRUE));

    $paymentProcessorTypeID = CRM_Core_DAO::getFieldValue('CRM_Financial_DAO_PaymentProcessorType', $this->_processorName, 'id', 'name');
    $paymentProcessorID = (int)civicrm_api3('PaymentProcessor', 'getvalue', array(
      'is_test' => ($this->_mode == 'test') ? 1 : 0,
      'options' => array('limit' => 1),
      'payment_processor_type_id' => $paymentProcessorTypeID,
      'return' => 'id',
    ));
    if (!$ipn->validateData($this->_paymentProcessor, $input, $ids, $objects, TRUE, $paymentProcessorID)) {
      CRM_Core_Error::debug_log_message("bbprioritycash Validation failed");
      return FALSE;
    }

    return $ipn->single($input, $ids, $objects, FALSE, FALSE);
  }

  static function formatAmount($amount, $size, $pad = 0) {
    $amount_str = preg_replace('/[\.,]/', '', strval($amount));
    $amount_str = str_pad($amount_str, $size, $pad, STR_PAD_LEFT);
    return $amount_str;
  }

  static function trimAmount($amount, $pad = '0') {
    return ltrim(trim($amount), $pad);
  }
}
