<?php

namespace JsonRPC;

require_once __DIR__.'/../vendor/autoload.php';

define ('CURLOPT_URL', 10002);
define ('CURLOPT_RETURNTRANSFER', 19913);
define ('CURLOPT_CONNECTTIMEOUT', 78);
define ('CURLOPT_MAXREDIRS', 68);
define ('CURLOPT_SSL_VERIFYPEER', 64);
define ('CURLOPT_POST', 47);
define ('CURLOPT_POSTFIELDS', 10015);
define ('CURLOPT_HTTPHEADER', 10023);
define ('CURLOPT_HEADERFUNCTION', 20079);
define ('CURLOPT_CAINFO', 10065);

function extension_loaded($extension) {
    return HttpClientTest::$functions->extension_loaded($extension);
}

function fopen($url, $mode, $use_include_path, $context)
{
    return HttpClientTest::$functions->fopen($url, $mode, $use_include_path, $context);
}

function stream_context_create(array $params)
{
    return HttpClientTest::$functions->stream_context_create($params);
}

function curl_init() {
    return HttpClientTest::$functions->curl_init();
}

function curl_setopt_array($ch, array $params) {
    HttpClientTest::$functions->curl_setopt_array($ch, $params);
}

function curl_setopt($ch, $option, $value) {
    HttpClientTest::$functions->curl_setopt($ch, $option, $value);
}

function curl_exec($ch) {
    return HttpClientTest::$functions->curl_exec($ch);
}

function curl_close($ch) {
    HttpClientTest::$functions->curl_close($ch);
}

class HttpClientTest extends \PHPUnit_Framework_TestCase
{
    public static $functions;

    public function setUp()
    {
        self::$functions = $this
            ->getMockBuilder('stdClass')
            ->setMethods(array('extension_loaded', 'fopen', 'stream_context_create',
                'curl_init', 'curl_setopt_array', 'curl_setopt', 'curl_exec', 'curl_close'))
            ->getMock();
    }

    public function testWithServerError()
    {
        $httpClient = new HttpClient();
        $httpClient->handleExceptions(array(
            'HTTP/1.0 301 Moved Permanently',
            'Connection: close',
            'HTTP/1.1 500 Internal Server Error',
        ));
        $this->assertInstanceOf('\JsonRPC\Exception\ServerErrorException', $httpClient->stateGet('exception'));
    }

    public function testWithConnectionFailure()
    {
        $httpClient = new HttpClient();
        $httpClient->handleExceptions(array(
            'HTTP/1.1 404 Not Found',
        ));
        $this->assertInstanceOf('\JsonRPC\Exception\ConnectionFailureException', $httpClient->stateGet('exception'));
    }

    public function testWithAccessForbidden()
    {
        $httpClient = new HttpClient();
        $httpClient->handleExceptions(array(
            'HTTP/1.1 403 Forbidden',
        ));
        $this->assertInstanceOf('\JsonRPC\Exception\AccessDeniedException', $httpClient->stateGet('exception'));
    }

    public function testWithAccessNotAllowed()
    {
        $httpClient = new HttpClient();
        $httpClient->handleExceptions(array(
            'HTTP/1.0 401 Unauthorized',
        ));
        $this->assertInstanceOf('\JsonRPC\Exception\AccessDeniedException', $httpClient->stateGet('exception'));
    }

    public function testWithCallback()
    {
        self::$functions
            ->expects($this->at(0))
            ->method('extension_loaded')
            ->with('curl')
            ->will($this->returnValue(false));

        self::$functions
            ->expects($this->at(1))
            ->method('stream_context_create')
            ->with(array(
                'http' => array(
                    'method' => 'POST',
                    'protocol_version' => 1.1,
                    'timeout' => 5,
                    'max_redirects' => 2,
                    'header' => implode("\r\n", array(
                        'User-Agent: JSON-RPC PHP Client <https://github.com/fguillot/JsonRPC>',
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'Connection: close',
                        'Content-Length: 4',
                    )),
                    'content' => 'test',
                    'ignore_errors' => true,
                ),
                'ssl' => array(
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                )
            ))
            ->will($this->returnValue('context'));

        self::$functions
            ->expects($this->at(2))
            ->method('fopen')
            ->with('url', 'r', false, 'context')
            ->will($this->returnValue(false));

        $httpClient = new HttpClient('url');
        $httpClient->withBeforeRequestCallback(function(HttpClient $client, $payload) {
            $client->withHeaders(array('Content-Length: '.strlen($payload)));
        });

        $this->setExpectedException('\JsonRPC\Exception\ConnectionFailureException');
        $httpClient->execute('test');
    }

    public function testWithCurl()
    {
        self::$functions
            ->expects($this->at(0))
            ->method('extension_loaded')
            ->with('curl')
            ->will($this->returnValue(true));

        self::$functions
            ->expects($this->at(1))
            ->method('curl_init')
            ->will($this->returnValue('curl'));

        self::$functions
            ->expects($this->at(2))
            ->method('curl_setopt_array')
            ->with('curl', array(
                CURLOPT_URL => 'url',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_MAXREDIRS => 2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => 'test',
                CURLOPT_HTTPHEADER => array(
                    'User-Agent: JSON-RPC PHP Client <https://github.com/fguillot/JsonRPC>',
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Connection: close',
                    'Content-Length: 4',
                ),
                CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$headers) {
                    $headers[] = $header;
                    return strlen($header);
                }
            ));

        self::$functions
            ->expects($this->at(3))
            ->method('curl_setopt')
            ->with('curl', CURLOPT_CAINFO, 'test.crt');

        self::$functions
            ->expects($this->at(4))
            ->method('curl_exec')
            ->with('curl')
            ->will($this->returnValue(false));

        self::$functions
            ->expects($this->at(5))
            ->method('curl_close')
            ->with('curl');

        $httpClient = new HttpClient('url');
        $httpClient
            ->withSslLocalCert('test.crt')
            ->withBeforeRequestCallback(function(HttpClient $client, $payload) {
                $client->withHeaders(array('Content-Length: '.strlen($payload)));
            });


        $this->setExpectedException('\JsonRPC\Exception\ConnectionFailureException');
        $httpClient->execute('test');
    }
}
