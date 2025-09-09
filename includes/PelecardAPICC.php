<?php

class PelecardAPICC {
    /******  Array of request data ******/
    var $vars_pay = array();
    var $caption_set = array();

    function setCS($key, $value) {
        $this->caption_set[$key] = $value;
    }

    /******  Set parameter ******/
    function setParameter($key, $value) {
        $this->vars_pay[$key] = $value;
    }

    /******  Get parameter ******/
    function getParameter($key) {
        return $this->vars_pay[$key] ?? NULL;
    }

    /******  Convert Hash to JSON ******/
    function arrayToJson() {
        if (!empty($this->caption_set)) {
            $this->setParameter('CaptionSet', $this->caption_set);
        }
        return json_encode($this->vars_pay); //(PHP 5 >= 5.2.0)
    }

    /******  Convert String to Hash ******/
    function stringToArray($data) {
        if (is_array($data)) {
            $this->vars_pay = $data;
        } else {
            $this->vars_pay = json_decode($data, true); //(PHP 5 >= 5.2.0)
        }
    }

    /****** Request URL from PeleCard ******/
    function getRedirectUrl(): array {
        // Push constant parameters
        $this->setParameter("ActionType", 'J4');
        $this->setParameter("CardHolderName", 'hide');
        $this->setParameter("CustomerIdField", 'hide');
        $this->setParameter("CreateToken", 'true');
        $this->setParameter("Cvv2Field", 'must');
        $this->setParameter("EmailField", 'hide');
        $this->setParameter("TelField", 'hide');
        $this->setParameter("FeedbackDataTransferMethod", 'POST');
        $this->setParameter("FirstPayment", 'auto');
        $this->setParameter("ShopNo", 100);
        $this->setParameter("SetFocus", 'CC');
        $this->setParameter("HiddenPelecardLogo", true);
        $cards = [
            "Amex" => true,
            "Diners" => false,
            "Isra" => true,
            "Master" => true,
            "Visa" => true,
        ];
        $this->setParameter("SupportedCards", $cards);

        $json = $this->arrayToJson();
        $this->connect($json, '/init');

        $error = $this->getParameter('Error');
        if (is_array($error)) {
            if ($error['ErrCode'] > 0) {
                return array($error['ErrCode'], $error['ErrMsg']);
            } else {
                return array(0, $this->getParameter('URL'));
            }
        } else {
            return array('000', 'Unknown error: ' . $error);
        }
    }

    function connect($params, $action) {
        $ch = curl_init('https://gateway20.pelecard.biz/PaymentGW' . $action);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER,
            array('Content-Type: application/json; charset=UTF-8', 'Content-Length: ' . strlen($params)));
        $result = curl_exec($ch);
        if ($result == '0') {
            $this->vars_pay = [
                'Error' => array(-1, 'Error')
            ];
        } elseif ($result == '1') {
            $this->vars_pay = [
                'Identified' => array(0, 'Identified')
            ];
        } else {
            $this->stringToArray($result);
        }
    }

    /****** Validate Response ******/
    function validateResponse($processor, $data, $contribution_id, $errors) {
        $PelecardTransactionId = $data['PelecardTransactionId'] . '';
        $PelecardStatusCode = $data['PelecardStatusCode'] . '';
        if ($PelecardStatusCode > 0) {
            $query_params = array(
                1 => array($PelecardStatusCode, 'String'),
                2 => array($contribution_id, 'String')
            );
            CRM_Core_DAO::executeQuery(
                'UPDATE civicrm_contribution SET invoice_number = %1, contribution_status_id = 4 WHERE id = %2', $query_params);

            CRM_Core_Error::debug_log_message("Error: " . $PelecardStatusCode);
            echo "<h1>Error: " . $PelecardStatusCode . ': ' . $errors[$PelecardStatusCode] . "</h1>";
            return [false, null];
        }

        $ConfirmationKey = $data['ConfirmationKey'] . '';
        $UserKey = $data['UserKey'] . '';

        $this->vars_pay = [];
        $this->setParameter("user", $processor["user_name"]);
        $this->setParameter("password", $processor["password"]);
        $this->setParameter("terminal", $processor["signature"]);
        $this->setParameter("TransactionId", $PelecardTransactionId);

        $json = $this->arrayToJson();
        $this->connect($json, '/GetTransaction');

        $error = $this->getParameter('Error');
        if (is_array($error) && $error['ErrCode'] > 0) {
            $query_params = array(
                1 => array($error['ErrCode'], 'String'),
                2 => array($contribution_id, 'String')
            );
            CRM_Core_DAO::executeQuery(
                'UPDATE civicrm_contribution SET invoice_number = %1, contribution_status_id = 4 WHERE id = %2', $query_params);

            CRM_Core_Error::debug_log_message("Error[{error}]: {message}", ["error" => $error['ErrCode'], "message" => $error['ErrMsg']]);
            return [false, null];
        }

        $data = $this->getParameter('ResultData');
        $this->stringToArray($data);

        $token = $data['Token'] . '';
        $approval = $data['DebitApproveNumber'] . '';
        $cardtype = $data['CreditCardCompanyIssuer'] . '';
        $cardnum = $data['CreditCardNumber'] . '';
        $cardexp = $data['CreditCardExpDate'] . '';
        $amount = $data['DebitTotal'] / 100.00;
        if ($data['DebitType'] == '51') {
            $amount = -$amount;
        }
        $installments = $data['TotalPayments'];
        if ($installments == 1) {
            $firstpay = $amount;
        } else {
            $firstpay = $data['FirstPaymentTotal'] / 100.00;
        }
        $updatedat = urldecode($data['TransactionUpdateTime'] . '');

        $this->vars_pay = [];
        $this->setParameter("ConfirmationKey", $ConfirmationKey);
        $this->setParameter("UniqueKey", $UserKey);
        $this->setParameter("TotalX100", $amount * 100);

        $json = $this->arrayToJson();
        $this->connect($json, '/ValidateByUniqueKey');

        $error = $this->getParameter('Error');
        if (is_array($error) && isset($error['ErrCode']) && $error['ErrCode'] > 0) {
            $query_params = array(
                1 => array($error['ErrCode'], 'String'),
                2 => array($contribution_id, 'String')
            );
            CRM_Core_DAO::executeQuery(
                'UPDATE civicrm_contribution SET invoice_number = %1, contribution_status_id = 4 WHERE id = %2', $query_params);

            CRM_Core_Error::debug_log_message("Error[{error}]: {message}", ["error" => $error['ErrCode'], "message" => $error['ErrMsg']]);
            return [false, null];
        }

        // Store all parameters in DB
        $query_params = array(
            1 => array($PelecardTransactionId, 'String'),
            2 => array($contribution_id, 'String'),
            3 => array($cardtype, 'String'),
            4 => array($cardnum, 'String'),
            5 => array($cardexp, 'String'),
            6 => array($firstpay, 'String'),
            7 => array($installments, 'String'),
            8 => array(http_build_query($data), 'String'),
            9 => array($amount, 'String'),
            10 => array($token, 'String'),
            11 => array($approval, 'String'),
            12 => array($updatedat, 'String'),
        );
        CRM_Core_DAO::executeQuery(
            'INSERT INTO civicrm_bb_payment_responses(trxn_id, cid, cardtype, cardnum, cardexp, firstpay, installments, response, amount, token, is_regular, approval, created_at) 
                   VALUES (%1, %2, %3, %4, %5, %6, %7, %8, %9, %10, 1, %11, NOW(), %12)', $query_params);

        return [true, $data];
    }

    function singlePayment(): ?array {
        $json = $this->arrayToJson();
        $this->Services($json, '/DebitRegularType');
        $code = +$this->getParameter('StatusCode');
        $error = $this->getParameter('ErrorMessage');
        if ($code > 0) {
            $result['success'] = false;
            $result['status_code'] = $code;
            $result['error_message'] = $error;
            return $result;
        }
        $result = array(
            'success' => true,
            'PelecardTransactionId' => $this->getParameter('PelecardTransactionId'),
            'approval' => $this->getParameter('approval'),
        );
        return $result;
    }

    function Services($params, $action) {
        $ch = curl_init('https://gateway20.pelecard.biz/services' . $action);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER,
            array('Content-Type: application/json; charset=UTF-8', 'Content-Length: ' . strlen($params)));
        $result = curl_exec($ch);
        if ($result == '0') {
            $this->vars_pay = [
                'Error' => array(-1, 'Error')
            ];
        } elseif ($result == '1') {
            $this->vars_pay = [
                'Identified' => array(0, 'Identified')
            ];
        } else {
            $this->stringToArray($result);
        }
    }

    /******  Base64 Functions  ******/
    function base64_url_encode($input): string {
        return strtr(base64_encode($input), '+/', '-_');
    }

    function base64_url_decode($input) {
        return base64_decode(strtr($input, '-_', '+/'));
    }
}
