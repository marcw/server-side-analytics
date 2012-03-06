<?php

namespace MarcW\ServerSideAnalytics;

use Buzz\Browser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Analytics
 *
 * @author Marc Weistroff <marc@weistroff.net>
 */
class Analytics
{
    const VERSION = '4.4sh';

    private $utmGifUrl = 'http://www.google-analytics.com/__utm.gif';

    private $browser;
    private $cookieName;
    private $cookiePath;
    private $cookiePersistence;
    private $trustProxy;

    private $request;

    public function __construct(Browser $browser, $cookieName = '__utmmobile', $cookiePath = '/', $cookiePersistence = 63072000, $trustProxy = false)
    {
        $this->browser = $browser;
        $this->cookieName = $cookieName;
        $this->cookiePath = $cookiePath;
        $this->cookiePersistence = $cookiePersistence;
        $this->trustProxy = false;
        $this->gifData =  array(
            chr(0x47), chr(0x49), chr(0x46), chr(0x38), chr(0x39), chr(0x61),
            chr(0x01), chr(0x00), chr(0x01), chr(0x00), chr(0x80), chr(0xff),
            chr(0x00), chr(0xff), chr(0xff), chr(0xff), chr(0x00), chr(0x00),
            chr(0x00), chr(0x2c), chr(0x00), chr(0x00), chr(0x00), chr(0x00),
            chr(0x01), chr(0x00), chr(0x01), chr(0x00), chr(0x00), chr(0x02),
            chr(0x02), chr(0x44), chr(0x01), chr(0x00), chr(0x3b)
        );
    }

    public function track(Request $request)
    {
        $this->request = $request;

        $referer = $this->request->query->get('utmr', '');
        $path = $this->request->query->get('utmp', '');
        $account = $this->request->query->get('utmac');
        $userAgent = $this->request->server->get('HTTP_USER_AGENT', '');
        $visitorId = $this->request->cookies->get($this->cookieName);
        if (empty($visitorId)) {
            $visitorId = $this->getVisitorId($this->getGuidHeader(), $account, $userAgent);
        }

        $url = $this->constructGifUrl($referer, $path, $account, $visitorId, $this->maskVisitorIp());
        $this->browser->get($url, array(
            'Accept-Language: '.$this->request->server->get('HTTP_ACCEPT_LANGUAGE'),
            'User-Agent: '.$userAgent,
        ));

        $cookie = new Cookie($this->cookieName, $visitorId, time() + $this->cookiePersistence, $this->cookiePath, $this->request->getHost());
        $response = new Response();
        $response->headers->add(array('Content-Type' => 'image/gif', 'Pragma' =>  'no-cache'));
        $response->setPrivate();
        $response->mustRevalidate();
        $response->setExpires(new \DateTime('-10 year'));
        $response->setContent(join($this->gifData));
        $response->headers->setCookie($cookie);

        return $response;
    }

    private function getGuidHeader()
    {
        $guidHeader = $this->request->server->get('HTTP_X_DCMGUID');
        if (empty($guidHeader)) {
            $guidHeader = $this->request->server->get('HTTP_X_UP_SUBNO');
        }
        if (empty($guidHeader)) {
            $guidHeader = $this->request->server->get('HTTP_X_JPHONE_UID');
        }
        if (empty($guidHeader)) {
            $guidHeader = $this->request->server->get('HTTP_X_EM_UID');
        }

        return $guidHeader;
    }

    private function getVisitorId($guid, $account, $userAgent)
    {
        $message = '';
        if (!empty($guid)) {
            $message = $guid.$account;
        } else {
            $message = $userAgent.uniqid($this->getRandomNumber(), true);
        }

        $hash = md5($message);

        return '0x'.substr($hash, 0, 16);
    }

    private function maskVisitorIp()
    {
        $ip = $this->request->getClientIp($this->trustProxy);
        $regex = "/^([^.]+\.[^.]+\.[^.]+\.).*/";
        if (preg_match($regex, $ip, $matches)) {
            return $matches[1] . "0";
        } else {
            return "";
        }
    }

    private function constructGifUrl($referer, $path, $account, $visitorId, $ip)
    {
        $query = http_build_query(array(
            'utmwv'  => self::VERSION,
            'utmn'   => $this->getRandomNumber(),
            'utmhn'  => $this->request->getHost(),
            'utmr'   => $referer,
            'utmp'   => $path,
            'utmac'  => $account,
            'utmcc'  => '__utma=999.999.999.999.999.1;',
            'utmvid' => $visitorId,
            'utmip'  => $ip,
        ));

        return $this->utmGifUrl.'?'.$query;
    }

    private function getRandomNumber()
    {
        return rand(0, 0x7fffffff);
    }
}

