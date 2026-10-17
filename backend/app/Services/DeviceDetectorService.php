<?php

namespace App\Services;

class DeviceDetectorService
{
    /**
     * Parsear la cabecera User-Agent para determinar tipo de dispositivo, SO y navegador.
     *
     * @param string|null $userAgent
     * @param string|null $ipAddress
     * @return array{
     *   device_type: string,
     *   os_name: string,
     *   browser_name: string,
     *   icon: string,
     *   is_tv: bool,
     *   is_mobile: bool
     * }
     */
    public function detect(?string $userAgent, ?string $ipAddress = null): array
    {
        $ua = $userAgent ? trim($userAgent) : '';

        // Valores por defecto
        $deviceType = 'browser';
        $osName = 'Desconocido';
        $browserName = 'Navegador Web';
        $icon = 'monitor';
        $isTv = false;
        $isMobile = false;

        if (empty($ua)) {
            return [
                'device_type' => $deviceType,
                'os_name' => $osName,
                'browser_name' => $browserName,
                'icon' => $icon,
                'is_tv' => false,
                'is_mobile' => false,
            ];
        }

        // 1. Detectar Smart TVs y Decodificadores IPTV
        if (preg_match('/(Tizen|SmartTV|SamsungBrowser\/)/i', $ua)) {
            $deviceType = 'smart_tv';
            $osName = 'Samsung Tizen OS';
            $browserName = 'Samsung Smart TV';
            $icon = 'smart_tv';
            $isTv = true;
        } elseif (preg_match('/(webOS|Web0S|NetCast|LG Browser)/i', $ua)) {
            $deviceType = 'smart_tv';
            $osName = 'LG webOS';
            $browserName = 'LG Smart TV App';
            $icon = 'smart_tv';
            $isTv = true;
        } elseif (preg_match('/(Android TV|GoogleTV|BRAVIA|AFTT|AFTM|FireTV|MiTV|MiBOX|NVIDIA SHIELD|Chromecast)/i', $ua)) {
            $deviceType = 'smart_tv';
            $osName = preg_match('/FireTV|AFT/i', $ua) ? 'Amazon Fire TV (OS)' : 'Android TV / Google TV';
            $browserName = 'FlixHN TV Player';
            $icon = 'smart_tv';
            $isTv = true;
        } elseif (preg_match('/(AppleTV|tvOS)/i', $ua)) {
            $deviceType = 'smart_tv';
            $osName = 'Apple tvOS';
            $browserName = 'FlixHN Apple TV';
            $icon = 'smart_tv';
            $isTv = true;
        } elseif (preg_match('/(Roku|RokuPlayer)/i', $ua)) {
            $deviceType = 'smart_tv';
            $osName = 'Roku OS';
            $browserName = 'FlixHN Roku Client';
            $icon = 'smart_tv';
            $isTv = true;
        }
        // 2. Detectar Móviles y Tablets (iOS / Android)
        elseif (preg_match('/(iPhone|iPad|iPod)/i', $ua)) {
            $deviceType = 'ios';
            $isMobile = true;
            $icon = 'apple';

            if (preg_match('/iPad/i', $ua)) {
                $osName = 'Apple iPadOS';
            } else {
                if (preg_match('/OS (\d+[_.]\d+)/i', $ua, $matches)) {
                    $osName = 'iOS ' . str_replace('_', '.', $matches[1]);
                } else {
                    $osName = 'iOS';
                }
            }

            if (preg_match('/FlixHN/i', $ua)) {
                $browserName = 'FlixHN App iOS';
            } elseif (preg_match('/CriOS\/([0-9\.]+)/i', $ua, $m)) {
                $browserName = 'Chrome iOS ' . explode('.', $m[1])[0];
            } elseif (preg_match('/FxiOS\/([0-9\.]+)/i', $ua, $m)) {
                $browserName = 'Firefox iOS ' . explode('.', $m[1])[0];
            } else {
                $browserName = 'Mobile Safari';
            }
        } elseif (preg_match('/Android/i', $ua)) {
            $deviceType = 'android';
            $isMobile = true;
            $icon = 'android';

            if (preg_match('/Android (\d+(\.\d+)?)/i', $ua, $matches)) {
                $osName = 'Android ' . $matches[1];
            } else {
                $osName = 'Android OS';
            }

            if (preg_match('/FlixHN/i', $ua)) {
                $browserName = 'FlixHN App Android';
            } elseif (preg_match('/Chrome\/([0-9\.]+)/i', $ua, $m)) {
                $browserName = 'Chrome Mobile ' . explode('.', $m[1])[0];
                $icon = 'chrome';
            } elseif (preg_match('/Firefox\/([0-9\.]+)/i', $ua, $m)) {
                $browserName = 'Firefox Mobile ' . explode('.', $m[1])[0];
                $icon = 'firefox';
            } else {
                $browserName = 'Navegador Android';
            }
        }
        // 3. Detectar Desktop / PC / Mac / Linux
        else {
            if (preg_match('/Windows NT 10\.0/i', $ua)) {
                $osName = 'Windows 11 / 10';
                $deviceType = 'desktop';
            } elseif (preg_match('/Windows NT 6\.3/i', $ua)) {
                $osName = 'Windows 8.1';
                $deviceType = 'desktop';
            } elseif (preg_match('/Windows NT/i', $ua)) {
                $osName = 'Windows';
                $deviceType = 'desktop';
            } elseif (preg_match('/Macintosh|Mac OS X/i', $ua)) {
                $osName = 'macOS';
                if (preg_match('/Mac OS X (\d+[_\.]\d+)/i', $ua, $m)) {
                    $osName = 'macOS ' . str_replace('_', '.', $m[1]);
                }
                $deviceType = 'desktop';
            } elseif (preg_match('/Linux/i', $ua)) {
                $osName = preg_match('/Ubuntu/i', $ua) ? 'Ubuntu Linux' : 'Linux OS';
                $deviceType = 'desktop';
            }

            // Detección de Navegador de Escritorio
            if (preg_match('/Edg\/([0-9\.]+)/i', $ua, $m)) {
                $browserName = 'Microsoft Edge ' . explode('.', $m[1])[0];
                $icon = 'edge';
            } elseif (preg_match('/OPR\/([0-9\.]+)|Opera/i', $ua, $m)) {
                $browserName = 'Opera Browser';
                $icon = 'browser';
            } elseif (preg_match('/Chrome\/([0-9\.]+)/i', $ua, $m)) {
                $browserName = 'Google Chrome ' . explode('.', $m[1])[0];
                $icon = 'chrome';
            } elseif (preg_match('/Firefox\/([0-9\.]+)/i', $ua, $m)) {
                $browserName = 'Mozilla Firefox ' . explode('.', $m[1])[0];
                $icon = 'firefox';
            } elseif (preg_match('/Safari\/([0-9\.]+)/i', $ua, $m) && !preg_match('/Chrome/i', $ua)) {
                $browserName = 'Apple Safari';
                $icon = 'safari';
            } else {
                $browserName = 'Navegador Web';
                $icon = 'browser';
            }
        }

        return [
            'device_type' => $deviceType,
            'os_name' => $osName,
            'browser_name' => $browserName,
            'icon' => $icon,
            'is_tv' => $isTv,
            'is_mobile' => $isMobile,
        ];
    }
}
