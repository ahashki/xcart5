<?php
// vim: set ts=4 sw=4 sts=4 et:

/**
 * Copyright (c) 2011-present Qualiteam software Ltd. All rights reserved.
 * See https://www.x-cart.com/license-agreement.html for license details.
 */

namespace XCart\Bus\Controller;

use Silex\Application;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use XCart\Bus\Auth\EmergencyCodeService;
use XCart\Bus\Auth\TokenService;
use XCart\Bus\Auth\XC5LoginService;
use XCart\Bus\Exception\XC5Unavailable;
use XCart\SilexAnnotations\Annotations\Router;
use XCart\SilexAnnotations\Annotations\Service;

/**
 * @Service\Service()
 * @Router\Controller()
 */
class Auth
{
    private const ADMIN_TTL = 43200;

    /**
     * @var TokenService
     */
    private $tokenService;

    /**
     * @var EmergencyCodeService
     */
    private $emergencyCodeService;

    /**
     * @var XC5LoginService
     */
    private $xc5LoginService;

    /**
     * @var string
     */
    private $domain;

    /**
     * @var string
     */
    private $cookiePath;

    /**
     * @var bool
     */
    private $debug;

    /**
     * @param Application          $app
     * @param TokenService         $tokenService
     * @param EmergencyCodeService $emergencyCodeService
     * @param XC5LoginService      $xc5LoginService
     *
     * @return static
     *
     * @Service\Constructor
     * @codeCoverageIgnore
     */
    public static function serviceConstructor(
        Application $app,
        TokenService $tokenService,
        EmergencyCodeService $emergencyCodeService,
        XC5LoginService $xc5LoginService
    ) {
        return new self(
            $tokenService,
            $emergencyCodeService,
            $xc5LoginService,
            parse_url($app['config']['domain'], \PHP_URL_HOST),
            $app['config']['webdir'],
            (bool) $app['config']['debug']
        );
    }

    /**
     * @param TokenService         $tokenService
     * @param EmergencyCodeService $emergencyCodeService
     * @param XC5LoginService      $xc5LoginService
     * @param string               $domain
     * @param string               $cookiePath
     * @param string               $debug
     */
    public function __construct(
        TokenService $tokenService,
        EmergencyCodeService $emergencyCodeService,
        XC5LoginService $xc5LoginService,
        $domain,
        $cookiePath,
        $debug
    ) {
        $this->tokenService         = $tokenService;
        $this->emergencyCodeService = $emergencyCodeService;
        $this->xc5LoginService      = $xc5LoginService;
        $this->domain               = $domain;
        $this->cookiePath           = $cookiePath;
        $this->debug                = $debug;
    }

    /**
     * @param Request $request
     *
     * @return Response
     *
     * @Router\Route(
     *     @Router\Request(method="MATCH", uri="/auth"),
     * )
     */
    public function indexAction(Request $request): Response
    {
        try {
            $authCode = $request->get('auth_code');

            $additionalTokenData = [];

            if ($authCode) {
                $shouldGenerateJWT = $this->emergencyCodeService->checkAuthCode($authCode);

                if (!$shouldGenerateJWT && $this->emergencyCodeService->checkServiceCode(md5($authCode))) {
                    $shouldGenerateJWT                                  = true;
                    $additionalTokenData[TokenService::TOKEN_READ_ONLY] = true;
                }
            } else {
                $xc5Cookie = $request->cookies->get(
                    $this->xc5LoginService->getCookieName()
                );

                $shouldGenerateJWT = $this->xc5LoginService->checkXC5Cookie($xc5Cookie);

                if (!$shouldGenerateJWT) {
                    return $request->isMethod('GET')
                        ? new RedirectResponse($this->xc5LoginService->getLoginURL())
                        : new JsonResponse(['redirectUrl' => $this->xc5LoginService->getLoginURL()], 403);
                }

                $verifyData = $this->xc5LoginService->getVerifyData($xc5Cookie);

                $additionalTokenData['admin_login'] = $verifyData['admin_login'] ?? '';
            }

            if ($shouldGenerateJWT) {
                $response = $request->isMethod('GET')
                    ? new RedirectResponse('service.php')
                    : new JsonResponse(null, 200);

                $cookie = $this->createCookie(
                    $this->tokenService->generateToken($additionalTokenData)
                );
                $response->headers->setCookie($cookie);

                return $response;
            }

            return new JsonResponse(null, 401);

        } catch (XC5Unavailable $e) {
            return $request->isMethod('GET')
                ? new RedirectResponse('service.php#/login')
                : new JsonResponse(null, 503);

        } catch (\Exception $e) {
            $error = null;

            if ($this->debug) {
                $error = $e->getMessage();
            }

            return new JsonResponse(null, 500, ['X-Error' => $error]);
        }
    }

    /**
     * @param Request $request
     *
     * @return Response|null
     */
    public function authChecker(Request $request): ?Response
    {
        $tokenData = $this->tokenService->decodeToken($request->cookies->get('bus_token'));
        if (!$tokenData) {
            return new Response(null, 401);
        }

        if (!empty($tokenData['admin_login'])) {
            $xc5Cookie = $request->cookies->get(
                $this->xc5LoginService->getCookieName()
            );

            if (!$xc5Cookie) {
                return new Response(null, 401);
            }
        }

        return null;
    }

    /**
     * @param $request
     * @param $response
     *
     * @return mixed
     */
    public function touchCookie(Request $request, Response $response)
    {
        if ($response->getStatusCode() === 200) {
            $xid = $request->cookies->get($this->xc5LoginService->getCookieName());
            if ($xid) {
                $response->headers->setCookie(
                    new Cookie($this->xc5LoginService->getCookieName(), $xid, time() + self::ADMIN_TTL, $this->cookiePath ?: '/', $this->domain)
                );
            }

            $busToken = $request->cookies->get('bus_token');
            if ($busToken) {
                $response->headers->setCookie(
                    new Cookie('bus_token', $busToken, time() + self::ADMIN_TTL, $this->cookiePath ?: '/', $this->domain)
                );
            }
        }

        return $response;
    }

    /**
     * @param string $jwt
     *
     * @return Cookie
     * @throws \InvalidArgumentException
     */
    private function createCookie($jwt): Cookie
    {
        $isSecure   = false;
        $isHttpOnly = true;

        return new Cookie(
            'bus_token',
            $jwt,
            0,
            $this->cookiePath ?: '/',
            $this->domain,
            $isSecure,
            $isHttpOnly,
            false,
            'strict'
        );
    }
}
