<?php

use Civi\Api4\Contribution;
use Civi\Api4\Contact;
use CRM\BBPelecard\API\PelecardCC;
use CRM\BBPelecard\Utils\ErrorCodes;

class CRM_Core_Payment_BBPriorityCCIPN {
    private PelecardCC $_bbpAPI;

    private array $_inputParameters = [];

    function __construct($inputData) {
        $this->_bbpAPI = new PelecardCC();
        $this->setInputParameters($inputData);
    }

    private function setInputParameters($inputData) {
        $this->_inputParameters = $inputData;
    }

    function main(&$paymentProcessor, &$input, &$ids): void {
        try {
            $contributionStatuses = array_flip(CRM_Contribute_BAO_Contribution::buildOptions('contribution_status_id', 'validate'));
            $contributionID = $input['contributionID'];
            $contactID = self::retrieve('contactID', 'Integer');
            $contribution = $this->getContribution($contributionID, $contactID);

            if ($input['PelecardStatusCode'] != ErrorCodes::RESPONSE_CODE_ACCEPTED) {
                if ($input['PelecardStatusCode'] != '' && $input['PelecardStatusCode'] != ' ') {
                  Civi::log('BBPCC IPN')->debug("BBPCC IPN Response: About to cancel contribution \n input: " . print_r($input, TRUE) . "\n ids: " . print_r($ids, TRUE));
                  Contribution::update(false)
                    ->addWhere('id', '=', $contribution['id'])
                    ->addValue('contribution_status_id', $contributionStatuses['Cancelled'])
                    ->addValue('cancel_date', date('Y-m-d H:i:s'))
                    ->addValue('cancel_reason', 'CC failure ' . $input['PelecardStatusCode'])
                    ->execute();
                  echo 'Contribution aborted due to invalid code received from Pelecard: ' . $input['PelecardStatusCode'];
                } else {
                  echo 'Processing the Contribution payment. Please verify it in your personal area a bit later.';
                }
                return;
            }

            $statusID = $contribution['contribution_status_id'];
            if ($statusID === $contributionStatuses['Completed']) {
                Civi::log('BBPCC IPN')->debug('returning since contribution has already been handled');
                return;
            }

            list($valid, $data) = $this->validateResult($paymentProcessor, $input, $contribution);
            if (!$valid) {
                echo("bbpriorityCC Validation failed");
                return;
            }

            $this->updateContribution($contribution, $contactID, $data, $contributionStatuses['Completed']);

            echo("bbpriorityCC IPN success");
            $this->redirectSuccess($input);
        } catch (Exception $e) {
            Civi::log('BBPCC IPN')->debug($e->getMessage());
            echo 'Invalid or missing data: ' . $e->getMessage();
        }
    }

  /**
   * @throws CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  function updateContribution($contribution, $contactID, $data, $status) {
        // mark payment status
        Contribution::update(false)
            ->addWhere('id', '=', $contribution['id'])
            ->addValue('contribution_status_id', $status)
            ->execute();

        // update custom fields
        $token = $data['Token'] . '';
        $cardtype = $data['CreditCardCompanyIssuer'] ? $data['CreditCardCompanyIssuer'] . '' : '';
        $cardnum = $data['CreditCardNumber'] ? $data['CreditCardNumber'] . '' : '';
        $cardexp = $data['CreditCardExpDate'] ? $data['CreditCardExpDate'] . '' : '';

        Contribution::update(false)
            ->addWhere('id', '=', $contribution['id'])
            ->addValue('Payment_details.token', $token)
            ->addValue('Payment_details.cardtype', $cardtype)
            ->addValue('Payment_details.cardnum', $cardnum)
            ->addValue('Payment_details.cardexp', $cardexp)
            ->execute();
        Contact::update(false)
            ->addWhere('id', '=', $contactID)
            ->addValue('general_token.gtoken', $token)
            ->execute();
    }

  /**
   * @throws CRM_Core_Exception
   */
  function getInput(&$input, &$ids) {
        $input = [
            // GET Parameters
            'module' => $this->retrieve('md', 'String'),
            'component' => $this->retrieve('md', 'String'),
            'qfKey' => $this->retrieve('qfKey', 'String', false),
            'contributionID' => $this->retrieve('contributionID', 'String'),
            'contactID' => $this->retrieve('contactID', 'String'),
            'eventID' => $this->retrieve('eventID', 'String', false),
            'participantID' => $this->retrieve('participantID', 'String', false),
            'membershipID' => $this->retrieve('membershipID', 'String', false),
            'relatedContactID' => $this->retrieve('relatedContactID', 'String', false),
            'onBehalfDupeAlert' => $this->retrieve('onBehalfDupeAlert', 'String', false),
            'returnURL' => $this->retrieve('returnURL', 'String', false),
            // POST Parameters
            'PelecardTransactionId' => $this->retrieve('PelecardTransactionId', 'String', false),
            'PelecardStatusCode' => $this->retrieve('PelecardStatusCode', 'String', false),
            'Token' => $this->retrieve('Token', 'String', false),
            'ConfirmationKey' => $this->retrieve('ConfirmationKey', 'String', false),
            'UserKey' => $this->retrieve('UserKey', 'String', false),
        ];

        $ids = [
            'contribution' => $input['contributionID'],
            'contact' => $input['contactID'],
        ];
        if ($input['module'] == "event") {
            $ids['event'] = $input['eventID'];
            $ids['participant'] = $input['participantID'];
        } else {
            $ids['membership'] = $input['membershipID'];
            $ids['related_contact'] = $input['relatedContactID'];
            $ids['onbehalf_dupe_alert'] = $input['onBehalfDupeAlert'];
        }
    }

    function redirectSuccess(&$input): void {
        $url = (new PelecardCC())->base64_url_decode($input['returnURL']);
        $key = "success";
        $value = "1";
        $url = preg_replace('/(.*)(?|&)' . $key . '=[^&]+?(&)(.*)/i', '$1$2$4', $url . '&');
        $url = substr($url, 0, -1);
        if (strpos($url, '?') === false) {
            $returnURL = ($url . '?' . $key . '=' . $value);
        } else {
            $returnURL = ($url . '&' . $key . '=' . $value);
        }

        // Print the tpl to redirect to success
        $template = CRM_Core_Smarty::singleton();
        $template->assign('url', $returnURL);
        print $template->fetch('CRM/Core/Payment/BbpriorityCC.tpl');
    }

    function validateResult(&$paymentProcessor, &$input, &$contribution): array {
        if ($input['UserKey'] != $input['qfKey']) {
            Civi::log('BBPCC IPN')->debug("Pelecard Response param UserKey is invalid");
            return [false, null];
        }

        $input['amount'] = $contribution['total_amount'];
        list($valid, $data, $errorCode) = $this->_bbpAPI->validateResponse($paymentProcessor, $input, $contribution);

        if (!$valid) {
            Civi::log('BBPCC IPN')->debug("Pelecard Response is invalid");

            // Update contribution with error code
            if ($errorCode > 0) {
                Contribution::update(false)
                    ->addWhere('id', '=', $contribution['id'])
                    ->addValue('invoice_number', (string)$errorCode)
                    ->addValue('contribution_status_id', 4) // Failed status
                    ->execute();
            }
            return [false, null];
        }

        // Store transaction data in civicrm_bb_payment_responses
        $this->storePaymentResponse($contribution['id'], $data);

        // Update transaction ID
        Contribution::update(false)
            ->addWhere('id', '=', $contribution['id'])
            ->addValue('trxn_id', $data['PelecardTransactionId'])
            ->execute();

        return [true, $data];
    }

    /**
     * Store payment response data in database
     */
    private function storePaymentResponse($contributionId, $data): void {
        $query_params = [
            1 => [$data['PelecardTransactionId'], 'String'],
            2 => [$contributionId, 'String'],
            3 => [$data['CreditCardCompanyIssuer'] ?? '', 'String'],
            4 => [$data['CreditCardNumber'] ?? '', 'String'],
            5 => [$data['CreditCardExpDate'] ?? '', 'String'],
            6 => [$data['FirstPaymentTotal'] ?? 0, 'String'],
            7 => [$data['TotalPayments'] ?? 1, 'String'],
            8 => [is_array($data['FullResponse']) ? http_build_query($data['FullResponse']) : $data['FullResponse'], 'String'],
            9 => [$data['DebitTotal'] ?? 0, 'String'],
        ];

        CRM_Core_DAO::executeQuery(
            'INSERT INTO civicrm_bb_payment_responses(trxn_id, cid, cardtype, cardnum, cardexp, firstpay, installments, response, amount, is_regular, created_at)
             VALUES (%1, %2, %3, %4, %5, %6, %7, %8, %9, 1, NOW())',
            $query_params
        );
    }

  /**
   * @throws CRM_Core_Exception
   * @throws Exception
   */
  public function retrieve($name, $type, $abort = TRUE, $default = NULL) {
        $value = CRM_Utils_Type::validate(
            empty($this->_inputParameters[$name]) ? $default : $this->_inputParameters[$name],
            $type,
            FALSE
        );
        if ($abort && $value === NULL) {
            throw new Exception("Could not find an entry for $name");
        }
        return $value;
    }

  /**
   * @throws Exception
   */
  private function getContribution($contribution_id, $contactID): array {
        try {
            $contribution = \Civi\Api4\Contribution::get(false)
                ->addWhere('id', '=', $contribution_id)
                ->execute()
                ->first();
                
            if (!$contribution) {
                throw new Exception('Failure: Could not find contribution record for ' . (int)$contribution_id);
            }
            
            if ((int)$contribution['contact_id'] !== $contactID) {
                Civi::log("Contact ID in IPN not found but contact_id found in contribution.");
            }
            
            return $contribution;
        } catch (Exception $e) {
            throw new Exception('Failure: Could not find contribution record for ' . (int)$contribution_id);
        }
    }
}
