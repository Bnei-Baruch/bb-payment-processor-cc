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
        $this->setParameter("ShopNo", 1000); // TODO: What should be shop number?
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
        }
    }

    function connect($params, $action)
    {
        // TODO: Read from settings
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

    /****** Validate Response ******/
    function validateResponse($processor, $data)
    {
        $PelecardTransactionId = $data['PelecardTransactionId'] . '';
        $PelecardStatusCode = $data['PelecardStatusCode'] . '';
        $ConfirmationKey = $data['ConfirmationKey'] . '';
        $UserKey = $data['UserKey'] . '';
        $amount = $data['amount'] . '';

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
        $installments = $data['TotalPayments'];
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
        if (is_array($error) && $error['ErrCode'] > 0) {
            CRM_Core_Error::debug_log_message("Error[{error}]: {message}", ["error" => $error['ErrCode'], "message" => $error['ErrMsg']]);
            return false;
        }

        // Store all parameters in DB
        $db = new InvoiceDb();
        if (!$db) {
            echo $db->lastErrorMsg();
            return false;
        }

        $insert = $db->prepare("UPDATE payments SET response = :response, 
          cardtype = :cardtype, cardnum = :cardnum, cardexp = :cardexp, firstpay = :firstpay, 
          installments: = :installments, success = 1 WHERE id = :id");
        if (!$insert) {
            echo $db->lastErrorMsg();
            return false;
        }
        $insert->bindValue(':id', $UserKey);
        $insert->bindValue(':cardtype', $cardtype);
        $insert->bindValue(':cardnum', $cardnum);
        $insert->bindValue(':cardexp', $cardexp);
        $insert->bindValue(':firstpay', $firstpay);
        $insert->bindValue(':response', implode(",", $data));
        $insert->bindParam(':installments', $installments);
        $result = $insert->execute();
        if (!$result) {
            echo $db->lastErrorMsg();
            return false;
        }
        $db->close();
        return true;
    }

    function storeParameters($params)
    {
        $db = new InvoiceDb();
        if (!$db) {
            echo $db->lastErrorMsg();
        }

        $insert = $db->prepare("INSERT INTO payments ( id, name, amount, currency, email, phone, address, event, participants, org, income, is46, success) VALUES ( 
                :id, :name, :amount, :currency, :email, :phone, :address, :event, :participants, :org, :income, :is46, :success)");
        if (!$insert) {
            echo $db->lastErrorMsg();
        }

        $insert->bindParam(':id', $params['id']);
        $insert->bindParam(':name', $params['name']);
        $insert->bindParam(':amount', $params['amount']);
        $insert->bindParam(':currency', $params['currency']);
        $insert->bindParam(':email', $params['email']);
        $insert->bindParam(':phone', $params['phone']);
        $insert->bindParam(':address', $params['address']);
        $insert->bindParam(':event', $params['event']);
        $insert->bindParam(':participants', $params['participants']);
        $insert->bindParam(':org', $params['org']);
        $insert->bindParam(':income', $params['income']);
        $insert->bindParam(':is46', $params['is46']);
        $insert->bindParam(':success', $params['success']);
        $insert->bindParam(':created_at', (new DateTime())->format('y-m-d H:i'));

        $result = $insert->execute();
        if (!$result) {
            echo $db->lastErrorMsg();
        }
        $db->close();
    }
}

class InvoiceDb extends SQLite3
{
    function __construct()
    {
        $this->open("db/bb2prio.db", SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $sql = <<<EOF
          CREATE TABLE IF NOT EXISTS payments (
            id            CHAR(50)  PRIMARY KEY NOT NULL,
            name          TEXT      NOT NULL,
            amount        REAL      NOT NULL,
            currency      CHAR(3)   NOT NULL,
            email         CHAR(100) NOT NULL,
            phone         CHAR(30)  NOT NULL,
            address       TEXT      NOT NULL,
            event         TEXT      NOT NULL,
            participants  INT       NOT NULL,
            org           CHAR(50)  NOT NULL,
            income        CHAR(20)  NOT NULL,
            is46          INTEGER   NOT NULL,
            cardtype      INTEGER,
            cardnum       CHAR(16)  DEFAULT '',
            cardexp       CHAR(5)   DEFAULT '',
            firstpay      REAL      DEFAULT '',
            installments  INT       NOT NULL,
            response      TEXT,
            success       INTEGER   DEFAULT 0,   
            reported2prio INTEGER   DEFAULT 0
          );
EOF;

        $this->exec($sql);
    }
}

/******  Base64 Functions  ******/
function base64_url_encode($input)
{
    return strtr(base64_encode($input), '+/', '-_');
}

function encodeBase64($data)
{
    $data = base64_encode($data);
    return $data;
}

function base64_url_decode($input)
{
    return base64_decode(strtr($input, '-_', '+/'));
}
