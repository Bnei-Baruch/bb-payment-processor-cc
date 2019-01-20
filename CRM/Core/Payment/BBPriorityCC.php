<?php

/**
 *
 * @package BBPriorityCC [after Dummy Payment Processor]
 * @author Gregory Shilin <gshilin@gmail.com>
 */

require_once 'CRM/Core/Payment.php';
require_once 'includes/PelecardAPI.php';
require_once 'BBPriorityCCIPN.php';

/**
 * BBPriorityCC payment processor
 */
class CRM_Core_Payment_BBPriorityCC extends CRM_Core_Payment
{
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
    public function setDoDirectPaymentResult($doDirectPaymentResult)
    {
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
    public $_processorName = null;

    /**
     * Constructor.
     *
     * @param string $mode
     *   The mode of operation: live or test.
     *
     * @param $paymentProcessor
     *
     */
    public function __construct($mode, &$paymentProcessor)
    {
        $this->_mode = $mode;
        $this->_paymentProcessor = $paymentProcessor;
        $this->_processorName = 'BB Payment CC';
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
    static function &singleton($mode, &$paymentProcessor)
    {
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
    public function doDirectPayment(&$params)
    {

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
    protected function supportsLiveMode()
    {
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
    public function &error($errorCode = NULL, $errorMessage = NULL)
    {
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
    public function checkConfig()
    {
        $config = CRM_Core_Config::singleton();
        $error = array();

        if (empty($this->_paymentProcessor["user_name"])) {
            $error[] = ts("Merchant Name is not set in the BBPCC Payment Processor settings.");
        }
        if (empty($this->_paymentProcessor["password"])) {
            $error[] = ts("Merchant Password is not set in the BBPCC Payment Processor settings.");
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
    public function getEditableRecurringScheduleFields()
    {
        return array('amount', 'next_sched_contribution_date');
    }

    function doTransferCheckout(&$params, $component = 'contribute')
    {
        /* DEBUG
            echo "<pre>";
            var_dump($this->_paymentProcessor);
            var_dump($params);
            echo "</pre>";
        http_build_query();
            exit();
              echo static::formatBacktrace(debug_backtrace());
        */

        global $base_url;
        global $language;
        $lang = strtoupper($language->language);

        $config = CRM_Core_Config::singleton();

        if ($component != 'contribute' && $component != 'event') {
            CRM_Core_Error::fatal(ts('Component is invalid'));
            exit();
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
            $contributionPageID = CRM_Utils_Array::value('contributionPageID', $params) ||
                CRM_Utils_Array::value('contribution_page_id', $params);
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

        $pelecard = new PelecardAPI;
        $merchantUrl = $base_url . '/' . strtolower($lang) . '/civicrm/payment/ipn?processor_id=' . $this->_paymentProcessor["id"] . '&mode=' . $this->_mode
            . '&md=' . $component . '&qfKey=' . $params["qfKey"] . '&' . $merchantUrlParams
            . '&returnURL=' . $pelecard->base64_url_encode($returnURL);

        $financialTypeID = self::array_column_recursive_first($params, "financialTypeID");
        if (empty($financialTypeID)) {
            $financialTypeID = self::array_column_recursive_first($params, "financial_type_id");
        }
        $financial_account_id = civicrm_api3('EntityFinancialAccount', 'getvalue', array(
            'return' => "financial_account_id",
            'entity_id' => $financialTypeID,
            'account_relationship' => 1,
        ));
        $contact_id = civicrm_api3('FinancialAccount', 'getvalue', array(
            'return' => "contact_id",
            'id' => $financial_account_id,
            'account_relationship' => 1,
        ));
        $nick_name = civicrm_api3('Contact', 'getvalue', array(
            'return' => "nick_name",
            'id' => $contact_id,
            'account_relationship' => 1,
        ));

        if ($nick_name == 'ben2') {
            if ($lang == 'HE') {
                $pelecard->setParameter("TopText", 'בני ברוך קבלה לעם');
                $pelecard->setParameter("BottomText", '© בני ברוך קבלה לעם');
                $pelecard->setParameter("Language", 'HE');
                $pelecard->setCS('cs_payments', 'מספר תשלומים (לתושבי ישראל בלבד)');
                $pelecard->setParameter('ShowConfirmationCheckbox', 'True');
                $pelecard->setParameter('TextOnConfirmationBox', 'אני מסכים עם תנאי השימוש');
                $pelecard->setParameter('ConfirmationLink', 'https://checkout.kabbalah.info/legacy-statement-crm-he.html');
            } elseif ($lang == 'RU') {
                $pelecard->setParameter("TopText", 'Бней Барух Каббала лаАм');
                $pelecard->setParameter("BottomText", '© Бней Барух Каббала лаАм');
                $pelecard->setParameter("Language", 'RU');
                $pelecard->setCS('cs_payments', 'Количество платежей (только для жителей Израиля)');
                $pelecard->setParameter('ShowConfirmationCheckbox', 'True');
                $pelecard->setParameter('TextOnConfirmationBox', 'Я согласен с условиями обслуживания');
                $pelecard->setParameter('ConfirmationLink', 'https://checkout.kabbalah.info/legacy-statement-crm-ru.html');
            } else {
                $pelecard->setParameter("TopText", 'Bnei Baruch Kabbalah laAm');
                $pelecard->setParameter("BottomText", '© Bnei Baruch Kabbalah laAm');
                $pelecard->setParameter("Language", 'EN');
                $pelecard->setCS('cs_payments', 'Number of installments (for Israel residents only)');
                $pelecard->setParameter('ShowConfirmationCheckbox', 'True');
                $pelecard->setParameter('TextOnConfirmationBox', 'I agree with the terms of service');
                $pelecard->setParameter('ConfirmationLink', 'https://checkout.kabbalah.info/legacy-statement-crm-en.html');
            }
            $pelecard->setParameter("LogoUrl", "https://checkout.kabbalah.info/logo1.png");
        } elseif ($nick_name == 'arvut2') {
            if ($lang == 'HE') {
                $pelecard->setParameter("TopText", 'תנועת הערבות לאיחוד העם');
                $pelecard->setParameter("BottomText", '© תנועת הערבות לאיחוד העם');
                $pelecard->setParameter("Language", 'HE');
                $pelecard->setCS('cs_payments', 'מספר תשלומים (לתושבי ישראל בלבד)');
            } elseif ($lang == 'RU') {
                $pelecard->setParameter("TopText", 'Общественное движение «Арвут»');
                $pelecard->setParameter("BottomText", '© Общественное движение «Арвут»');
                $pelecard->setParameter("Language", 'RU');
                $pelecard->setCS('cs_payments', 'Количество платежей (только для жителей Израиля)');
            } else {
                $pelecard->setParameter("TopText", 'The Arvut Social Movement');
                $pelecard->setParameter("BottomText", '© The Arvut Social Movement');
                $pelecard->setParameter("Language", 'EN');
                $pelecard->setCS('cs_payments', 'Number of installments (for Israel residents only)');
            }
            $pelecard->setParameter("LogoUrl", "https://checkout.arvut.org/arvut_logo.png");
        }

        $pelecard->setParameter("user", $this->_paymentProcessor["user_name"]);
        $pelecard->setParameter("password", $this->_paymentProcessor["password"]);
        $pelecard->setParameter("terminal", $this->_paymentProcessor["signature"]);

        $pelecard->setParameter("UserKey", $params['qfKey']);
        $pelecard->setParameter("ParamX", 'civicrm-' . $params['contributionID']);

        //    $sandBoxUrl = 'https://gateway20.pelecard.biz/sandbox/landingpage?authnum=123';
        $pelecard->setParameter("GoodUrl", $merchantUrl); // ReturnUrl should be used _AFTER_ payment confirmation
        $pelecard->setParameter("ErrorUrl", $merchantUrl);
        $pelecard->setParameter("CancelUrl", $cancelURL);
        // Free amount example
        // $pelecard->setParameter("Total", 0);
        // $pelecard->setParameter("FreeTotal", true);
        // if ($lang == 'HE') {
            // $text = "אנא הכנס סכום מתאים";
            // $pelecard->setParameter("CssURL", "https://checkout.kabbalah.info/variant-he-1.css");
        // } elseif ($lang == 'RU') {
            // $text = "Введите правильную сумму";
            // $pelecard->setParameter("CssURL", "https://checkout.kabbalah.info/variant-en-1.css");
        // } else {
            // $text = "Please Select Proper Sum";
            // $pelecard->setParameter("CssURL", "https://checkout.kabbalah.info/variant-en-1.css");
        // }
        // $pelecard->setCS("cs_free_total", $text);
        if (($params["amount"] * 100) <= 0) {
            CRM_Core_Error::fatal(ts('Amount must be positive!!!'));
            exit();
        } else {
            $pelecard->setParameter("Total", $params["amount"] * 100);
        }
        if ($params["currencyID"] == "EUR") {
            $currency = 978;
        } elseif ($params["currencyID"] == "USD") {
            $currency = 2;
        } else { // ILS -- default
            $currency = 1;
        }
        $pelecard->setParameter("Currency", $currency);
        $pelecard->setParameter("MinPayments", 1);

        $installments = civicrm_api3('FinancialAccount', 'getvalue', array(
            'return' => "account_type_code",
            'id' => $financial_account_id,
        ));
        if ((int)$installments == 0) {
            $pelecard->setParameter("MaxPayments", 1);
        } else {
            $pelecard->setParameter("MaxPayments", $installments);
        }
        $name = $params['first_name'] . ' ' . $params['last_name'];

        $result = $pelecard->getRedirectUrl();
        $error = $result[0];
        if ($error > 0) {
            $message = $result[1];
            CRM_Core_Error::debug_log_message("Error[{error}]: {message}", ["error" => $error, "message" => $message]);
            return FALSE;
        } else {
            $url = $result[1];
        }

        // Print the tpl to redirect to Pelecard
        $template = CRM_Core_Smarty::singleton();
        $template->assign('url', $url);
        print $template->fetch('CRM/Core/Payment/BbpriorityCC.tpl');

        CRM_Utils_System::civiExit();
    }

    public function handlePaymentNotification()
    {
        $input = $ids = $objects = array();
        $ipn = new CRM_Core_Payment_BBPriorityCCIPN();

        // load vars in $input, &ids
        $ipn->getInput($input, $ids);

        $paymentProcessorTypeID = CRM_Core_DAO::getFieldValue('CRM_Financial_DAO_PaymentProcessorType', $this->_processorName, 'id', 'name');
        $paymentProcessorID = (int)civicrm_api3('PaymentProcessor', 'getvalue', array(
            'is_test' => ($this->_mode == 'test') ? 1 : 0,
            'options' => array('limit' => 1),
            'payment_processor_type_id' => $paymentProcessorTypeID,
            'return' => 'id',
        ));
        if (!$ipn->validateResult($this->_paymentProcessor, $input, $ids, $objects, TRUE, $paymentProcessorID)) {
            // CRM_Core_Error::debug_log_message("bbpriorityCC Validation failed");
            echo("bbpriorityCC Validation failed");
            exit();
        }

        if ($ipn->single($input, $ids, $objects, FALSE, FALSE)) {
            $returnURL = (new PelecardAPI)->base64_url_decode($input['returnURL']);

            // Print the tpl to redirect to success
            $template = CRM_Core_Smarty::singleton();
            $template->assign('url', $returnURL);
            print $template->fetch('CRM/Core/Payment/BbpriorityCC.tpl');

            CRM_Utils_System::civiExit();
        } else {
            CRM_Core_Error::debug_log_message("VALIDATION FAILED");
            echo("VALIDATION FAILED");
            exit();
        }
    }

    static function formatAmount($amount, $size, $pad = 0)
    {
        $amount_str = preg_replace('/[\.,]/', '', strval($amount));
        $amount_str = str_pad($amount_str, $size, $pad, STR_PAD_LEFT);
        return $amount_str;
    }

    static function trimAmount($amount, $pad = '0')
    {
        return ltrim(trim($amount), $pad);
    }

    /* Return dashed field (like email-4) from array */
    function getField($array, $field)
    {
        if (array_key_exists($field, $array)) {
            return $array[$field];
        }

        $keys = array_keys($array);
        $pattern = "/^" . $field . "/";
        $result = preg_grep($pattern, $keys);
        if (empty($result)) {
            return '';
        } else {
            return $array[array_values($result)[0]];
        }
    }

    /* Find first occurrence of needle somewhere in haystack (on all levels) */
    static function array_column_recursive_first(array $haystack, $needle)
    {
        $found = [];
        array_walk_recursive($haystack, function ($value, $key) use (&$found, $needle) {
            if (gettype($key) == 'string' && $key == $needle) {
                $found[] = $value;
            }
        });
        return $found[0];
    }
}
