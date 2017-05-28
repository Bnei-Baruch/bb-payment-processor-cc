<?php

class PelecardAPI {
  /******  Array of request data ******/
  var $vars_pay = array();

  /******  Set parameter ******/
  function setParameter($key, $value) {
    $this->vars_pay[$key] = $value;
  }

  /******  Get parameter ******/
  function getParameter($key) {
    return $this->vars_pay[$key];
  }

  /****** Request URL from PeleCard ******/
  function getRedirectUrl() {
    // Push constant parameters
    $this->setParameter("ActionType", 'J4');
    $this->setParameter("CardHolderName", 'hide');
    $this->setParameter("CustomerIdField", 'hide');
    $this->setParameter("Cvv2Field", 'must');
    $this->setParameter("EmailField", 'hide');
    $this->setParameter("TelField", 'hide');
    $this->setParameter("FeedbackDataTransferMethod", 'POST');
    $this->setParameter("FirstPayment", 'auto');
    $this->setParameter("ShopNo", 1000); // ZZZ
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

  function connect($params, $action) {
    var_dump($params); // ZZZ
    $ch = curl_init('https://gateway20.pelecard.biz/PaymentGW' . $action);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER,
      array('Content-Type: application/json; charset=UTF-8', 'Content-Length: ' . strlen($params)));
    $result = curl_exec($ch);
    $this->stringToArray($result);
  }

  /******  Convert Hash to JSON ******/
  function arrayToJson() {
    return json_encode($this->vars_pay); //(PHP 5 >= 5.2.0)
  }

  /******  Convert String to Hash ******/
  function stringToArray($data) {
    $this->vars_pay = json_decode($data, true); //(PHP 5 >= 5.2.0)
  }
}
