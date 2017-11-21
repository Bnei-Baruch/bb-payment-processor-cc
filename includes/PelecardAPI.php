<?php

class PelecardAPI
{
    /******  Array of request data ******/
    var $vars_pay = array();

    /******  Set parameter ******/
    function setParameter($key, $value)
    {
        $this->vars_pay[$key] = $value;
    }

    /******  Get parameter ******/
    function getParameter($key)
    {
        if (isset($this->vars_pay[$key])) {
            return $this->vars_pay[$key];
        } else {
            return NULL;
        }
    }

    /******  Convert Hash to JSON ******/
    function arrayToJson()
    {
        return json_encode($this->vars_pay); //(PHP 5 >= 5.2.0)
    }

    /******  Convert String to Hash ******/
    function stringToArray($data)
    {
        if (is_array($data)) {
            $this->vars_pay = $data;
        } else {
            $this->vars_pay = json_decode($data, true); //(PHP 5 >= 5.2.0)
        }
    }

    /****** Request URL from PeleCard ******/
    function getRedirectUrl()
    {
        // Push constant parameters
        $this->setParameter("ActionType", 'J4');
        $this->setParameter("CardHolderName", 'hide');
        $this->setParameter("CustomerIdField", 'hide');
        $this->setParameter("Cvv2Field", 'must');
        $this->setParameter("EmailField", 'hide');
        $this->setParameter("TelField", 'hide');
        $this->setParameter("FeedbackDataTransferMethod", 'POST');
        $this->setParameter("FirstPayment", 'auto');
        $this->setParameter("ShopNo", 1000);
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

    function connect($params, $action)
    {
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
    function validateResponse($processor, $data, $contribution, $errors)
    {
        $cid = $contribution->id;

        $PelecardTransactionId = $data['PelecardTransactionId'] . '';
        $PelecardStatusCode = $data['PelecardStatusCode'] . '';
        if ($PelecardStatusCode > 0) {
            CRM_Core_Error::debug_log_message("Error: " . $PelecardStatusCode);
            echo "<h1>Error: ". $PelecardStatusCode . ': ' . $errors[$PelecardStatusCode] . "</h1>";
            return false;
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
            CRM_Core_Error::debug_log_message("Error[{error}]: {message}", ["error" => $error['ErrCode'], "message" => $error['ErrMsg']]);
            return false;
        }

        $data = $this->getParameter('ResultData');
        $this->stringToArray($data);

        $cardtype = $data['CreditCardCompanyClearer'] . '';
        $cardnum = $data['CreditCardNumber'] . '';
        $cardexp = $data['CreditCardExpDate'] . '';
        $amount = $data['DebitTotal'] / 100.00;
        $installments = $data['DebitTotal'];
        if ($installments == 1) {
            $firstpay = $amount;
        } else {
            $firstpay = $data['FirstPaymentTotal'];
        }

        $this->vars_pay = [];
        $this->setParameter("ConfirmationKey", $ConfirmationKey);
        $this->setParameter("UniqueKey", $UserKey);
        $this->setParameter("TotalX100", $amount * 100);

        $json = $this->arrayToJson();
        $this->connect($json, '/ValidateByUniqueKey');

        $error = $this->getParameter('Error');
        if (is_array($error) && isset($error['ErrCode']) && $error['ErrCode'] > 0) {
            CRM_Core_Error::debug_log_message("Error[{error}]: {message}", ["error" => $error['ErrCode'], "message" => $error['ErrMsg']]);
            return false;
        }

        // Store all parameters in DB
        $query_params = array(
            1 => array($PelecardTransactionId, 'String'),
            2 => array($cid, 'String'),
            3 => array($cardtype, 'String'),
            4 => array($cardnum, 'String'),
            5 => array($cardexp, 'String'),
            6 => array($firstpay, 'String'),
            7 => array($installments, 'String'),
            8 => array(http_build_query($data), 'String'),
            9 => array($amount, 'String'),
        );
        CRM_Core_DAO::executeQuery(
            'INSERT INTO civicrm_bb_payment_responses(trxn_id, cid, cardtype, cardnum, cardexp, firstpay, installments, response, amount, created_at) 
                   VALUES (%1, %2, %3, %4, %5, %6, %7, %8, %9, NOW())', $query_params);
        return $PelecardTransactionId;
    }

    /******  Base64 Functions  ******/
    function base64_url_encode($input)
    {
        return strtr(base64_encode($input), '+/', '-_');
    }

    function base64_url_decode($input)
    {
        return base64_decode(strtr($input, '-_', '+/'));
    }
}
