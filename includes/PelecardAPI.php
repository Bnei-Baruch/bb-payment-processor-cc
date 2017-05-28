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
    var_dump($json);
    exit(0);
    $res = $this->connect($json, '/init');
    $err = $res[0];
    $msg = $res[1];
    if ($err == 0) {
      $msg = $msg['URL'];
    }
    return array($err, $msg);
  }

  function connect($params, $action) {


    return array(0, 'Initialized');
  }

  //////////////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////////////
  ////////////	   FUNCIONES PARA LA GENERACIÓN DEL FORMULARIO DE PAGO:		      	  ////////////
  //////////////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////////////

  /******  Obtener Número de pedido ******/
  function getOrder() {
    if (empty($this->vars_pay['DS_MERCHANT_ORDER'])) {
      $numPedido = $this->vars_pay['Ds_Merchant_Order'];
    } else {
      $numPedido = $this->vars_pay['DS_MERCHANT_ORDER'];
    }
    return $numPedido;
  }

  /******  Convertir Array en Objeto JSON ******/
  function arrayToJson() {
    $json = json_encode($this->vars_pay); //(PHP 5 >= 5.2.0)
    return $json;
  }

  function createMerchantParameters() {
    // Se transforma el array de datos en un objeto Json
    $json = $this->arrayToJson();
    // Se codifican los datos Base64
    return $this->encodeBase64($json);
  }

  function createMerchantSignature($key) {
    // Se decodifica la clave Base64
    $key = $this->decodeBase64($key);
    // Se genera el parámetro Ds_MerchantParameters
    $ent = $this->createMerchantParameters();
    // Se diversifica la clave con el Número de Pedido
    $key = $this->encrypt_3DES($this->getOrder(), $key);
    // MAC256 del parámetro Ds_MerchantParameters
    $res = $this->mac256($ent, $key);
    // Se codifican los datos Base64
    return $this->encodeBase64($res);
  }



  //////////////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////////////
  //////////// FUNCIONES PARA LA RECEPCIÓN DE DATOS DE PAGO (Notif, URLOK y URLKO): ////////////
  //////////////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////////////

  /******  Obtener Número de pedido ******/
  function getOrderNotif() {
    $numPedido = "";
    if (empty($this->vars_pay['Ds_Order'])) {
      $numPedido = $this->vars_pay['DS_ORDER'];
    } else {
      $numPedido = $this->vars_pay['Ds_Order'];
    }
    return $numPedido;
  }

  function getOrderNotifSOAP($datos) {
    $posPedidoIni = strrpos($datos, "<Ds_Order>");
    $tamPedidoIni = strlen("<Ds_Order>");
    $posPedidoFin = strrpos($datos, "</Ds_Order>");
    return substr($datos, $posPedidoIni + $tamPedidoIni, $posPedidoFin - ($posPedidoIni + $tamPedidoIni));
  }

  function getRequestNotifSOAP($datos) {
    $posReqIni = strrpos($datos, "<Request");
    $posReqFin = strrpos($datos, "</Request>");
    $tamReqFin = strlen("</Request>");
    return substr($datos, $posReqIni, ($posReqFin + $tamReqFin) - $posReqIni);
  }

  function getResponseNotifSOAP($datos) {
    $posReqIni = strrpos($datos, "<Response");
    $posReqFin = strrpos($datos, "</Response>");
    $tamReqFin = strlen("</Response>");
    return substr($datos, $posReqIni, ($posReqFin + $tamReqFin) - $posReqIni);
  }

  /******  Convertir String en Array ******/
  function stringToArray($datosDecod) {
    $this->vars_pay = json_decode($datosDecod, true); //(PHP 5 >= 5.2.0)
  }

  function decodeMerchantParameters($datos) {
    // Se decodifican los datos Base64
    $decodec = $this->base64_url_decode($datos);
    return $decodec;
  }

  function createMerchantSignatureNotif($key, $datos) {
    // Se decodifica la clave Base64
    $key = $this->decodeBase64($key);
    // Se decodifican los datos Base64
    $decodec = $this->base64_url_decode($datos);
    // Los datos decodificados se pasan al array de datos
    $this->stringToArray($decodec);
    // Se diversifica la clave con el Número de Pedido
    $key = $this->encrypt_3DES($this->getOrderNotif(), $key);
    // MAC256 del parámetro Ds_Parameters que envía Redsys
    $res = $this->mac256($datos, $key);
    // Se codifican los datos Base64
    return $this->base64_url_encode($res);
  }

  /******  Notificaciones SOAP ENTRADA ******/
  function createMerchantSignatureNotifSOAPRequest($key, $datos) {
    // Se decodifica la clave Base64
    $key = $this->decodeBase64($key);
    // Se obtienen los datos del Request
    $datos = $this->getRequestNotifSOAP($datos);
    // Se diversifica la clave con el Número de Pedido
    $key = $this->encrypt_3DES($this->getOrderNotifSOAP($datos), $key);
    // MAC256 del parámetro Ds_Parameters que envía Redsys
    $res = $this->mac256($datos, $key);
    // Se codifican los datos Base64
    return $this->encodeBase64($res);
  }

  /******  Notificaciones SOAP SALIDA ******/
  function createMerchantSignatureNotifSOAPResponse($key, $datos, $numPedido) {
    // Se decodifica la clave Base64
    $key = $this->decodeBase64($key);
    // Se obtienen los datos del Request
    $datos = $this->getResponseNotifSOAP($datos);
    // Se diversifica la clave con el Número de Pedido
    $key = $this->encrypt_3DES($numPedido, $key);
    // MAC256 del parámetro Ds_Parameters que envía Redsys
    $res = $this->mac256($datos, $key);
    // Se codifican los datos Base64
    return $this->encodeBase64($res);
  }
}
