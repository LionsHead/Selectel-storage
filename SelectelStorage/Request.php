<?php

namespace SelectelStorage;

use SelectelStorage\Container;
use SelectelStorage\StorageApi;

class Request {

    private $ch = NULL;
    private $url = 'https://auth.selcdn.ru';
    protected $result = [];
    protected $params = [];

    const CURL_TIMEOUT = 120;
    const CURL_CONNECTTIMEOUT = 5;
    const FORMAT = 'json';

    public function __construct() {
        
    }
    
    /**
     * инициализация curl с общими параметрами
     * @param array $headers
     * @return \SelectelStorage\Request
     */
    public function curlInit(array $headers = []) {
        $this->ch = curl_init($this->getUrl());

        curl_setopt_array($this->ch, [
            // params
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_FOLLOWLOCATION, FALSE,
            // timeout
            CURLOPT_CONNECTTIMEOUT => Request::CURL_CONNECTTIMEOUT,
            CURLOPT_TIMEOUT => Request::CURL_TIMEOUT,
            //ssl
            CURLOPT_SSL_VERIFYPEER => FALSE,
            // headers
            CURLOPT_HEADER => TRUE,
            CURLOPT_HTTPHEADER => array_merge(["Expect:"], $headers)
        ]);
        return $this;
    }

    /**
     * обработка запросов
     * @param string $method - по-умолчанию отправляет get
     * @return \SelectelStorage\Request
     */
    public function send($method = 'GET') {
        if (is_null($this->ch)) {
            $this->curlInit();
        }

        //  типы запросов
        switch ($method) {
            case 'GET':
                $this->url .= '?' . http_build_query($this->params); 
                curl_setopt($this->ch, CURLOPT_HTTPGET, TRUE);
                break;
            case 'POST':
                curl_setopt($this->ch, CURLOPT_POST, TRUE);
                curl_setopt($this->ch, CURLOPT_POSTFIELDS, http_build_query($this->params));
                break;
            case 'HEAD':
                $this->url .= '?' . http_build_query($this->params); 
                curl_setopt($this->ch, CURLOPT_NOBODY, TRUE);
                break;
            case 'PUT':
                 $this->url .= '?' . http_build_query($this->params);
                curl_setopt($this->ch, CURLOPT_PUT, TRUE);
                break;
            default:
                curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $method); // без доп параметров
                break;
        }
        $this->params = [];
        
        curl_setopt($this->ch, CURLOPT_URL, $this->url);

        return $this->setResponse(curl_exec($this->ch));
    }

    /**
     *  обработка ответа
     * @param type $response
     * @return \SelectelStorage\Request
     */
    private function setResponse($response) {
        $response = explode("\r\n\r\n", $response);
        $this->result['info'] = curl_getinfo($this->ch);
        $code = explode("\r\n", $response[0]);
        $this->result['headers']['HTTP-Code'] = (int) str_replace("HTTP/1.1", '', $code[0]);

        preg_match_all("/([A-z\-]+)\: (.*)\r\n/", $response[0], $headers, PREG_SET_ORDER); // получение заголовков
        foreach ($headers as $value) {
            $this->result['headers'][strtolower($value[1])] = $value[2];
        }
        unset($response[0]);

        $this->result['content'] = implode("\r\n\r\n", $response);
        return $this;
    }
    
    /**
     * 
     * @param string $file
     * @param int $filesize
     * @return \SelectelStorage\Request
     */
    public function setFile($file, $filesize){
        curl_setopt($this->ch, CURLOPT_INFILE, $file);
        curl_setopt($this->ch, CURLOPT_INFILESIZE, $filesize);
        
        return $this;
    }

    /**
     * изменение адреса для запроса
     * @param string $url
     * @return \SelectelStorage\Request
     */
    public function setUrl($url) {
        $this->url = $url;
        return $this;
    }
    
    /**
     *  получение адреса для запроса
     * @return type
     */
    public function getUrl(){
        return $this->url;
    }

        /**
     * 
     * @param type $params
     * @return \SelectelStorage\Request
     */
    public function setParams($params) {
        $this->params = $params;
        return $this;
    }

    public function getResult() {
        return $this->result;
    }

    public function getHeaders() {
        return $this->result['headers'];
    }

    public function getContent() {
        return $this->result['content'];
    }

    public function getInfo() {
        return $this->result['info'];
    }

}
