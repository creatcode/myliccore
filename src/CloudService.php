<?php

namespace safeaccess;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use think\Config;
use think\Db;

class CloudService
{
    protected static $instance = null;

    protected $cloudconfig;

    protected static $authpath    = APP_PATH . "common/license/register.lock";
    protected static $licensepath = APP_PATH . "common/license/license.lic";

    protected static $error = null;

    protected function __construct()
    {
        $this->cloudconfig = Config::get('cloud');
    }

    /**
     * 初始化
     *
     * @return self
     */
    public static function init()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 站点注册
     */
    public function register(string $name)
    {
        $response = $this->sendRequest(
            "/api/index/site_reg",
            [
                'name'    => $name,
                'code'    => $this->getcode(),
                'url'     => request()->domain(true),
                'version' => $this->cloudconfig['version'] ?? '1.0.0',
            ],
            'post',
            false
        );

        if (!$response || ($response['code'] ?? 0) == 0) {
            exception($response['msg'] ?? '云端服务异常');
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $secretKey = trim((string) ($data['secret_key'] ?? ''));
        $license = trim((string) ($data['license'] ?? ''));
        $licenseDir = dirname(self::$authpath);

        if ($secretKey === '') {
            exception('云端返回的授权信息不完整');
        }

        if (!is_dir($licenseDir) && !@mkdir($licenseDir, 0755, true)) {
            exception('创建授权目录失败，请检查目录权限');
        }

        if (file_put_contents(self::$authpath, $secretKey, LOCK_EX) === false) {
            exception('保存授权锁文件失败');
        }

        // license 文件不是必需项，只有云端返回内容时才落盘
        if ($license !== '' && file_put_contents(self::$licensepath, $license, LOCK_EX) === false) {
            exception('保存授权文件失败');
        }
    }

    /**
     * 授权检测
     */
    public function checkAuth()
    {
        $env = $this->cloudconfig['env'] ?? '';
        switch ($env) {
            case 'local':
                // 授权文件检测
                $license = $this->checklicense();
                if ($license === false) {
                    abort(403, self::getError() ?? '当前未授权,暂无法运行~');
                }
                break;
            case 'online':
                // 远程检测
                $baseurl = trim($this->cloudconfig['url'] ?? '');
                if ($baseurl === '') {
                    abort(403, '请先配置云端地址');
                }

                if (!preg_match('/^https?:\/\//i', $baseurl)) {
                    $baseurl = 'http://' . $baseurl;
                }
                try {
                    $client = new Client([
                        'timeout'         => 2,
                        'connect_timeout' => 2,
                        'http_errors'     => false,
                    ]);
                    $type = trim((string) ($this->cloudconfig['type'] ?? ''));
                    if ($type === '') {
                        abort(403, '请先配置 cloud.type');
                    }

                    $response = $client->post(rtrim($baseurl, '/') . '/api/index/checkauth', [
                        'form_params' => [
                            'code' => $this->getcode(),
                            'type' => $type,
                        ],
                    ]);
                    $body    = $response->getBody();
                    $content = $body->getContents();
                    $json    = (array) json_decode($content, true);
                } catch (\Throwable $th) {
                    abort(403, '云端授权检测失败，请联系服务商');
                }
                if (empty($json)) {
                    abort(403, '云端授权异常:授权检测失败');
                }
                if ($json['code'] != 1) {
                    abort(403, $json['msg'] ?? '授权检测失败');
                }
                $license = is_array($json['data'] ?? null) ? $json['data'] : [];
                break;
            default:
                abort(403, '运行环境异常，请确认云端配置文件正常');
        }
        $this->checkdev($license['devnum'] ?? 0);
        return $license;
    }

    /**
     * 获取服务器唯一编码
     *
     * Windows 下不执行 wmic、ipconfig、powershell 等系统命令，避免被杀毒软件拦截。
     *
     * @param boolean $isforce
     * @return string
     */
    public function getcode($isforce = false)
    {
        $os = PHP_OS_FAMILY;

        $enkey = hash('sha256', 'hello world');
        $iv    = substr($enkey, 0, 16);

        $filename  = md5("{$os}_machine_code") . ".dat";
        $cachePath = RUNTIME_PATH . $filename;
        $licenseDir = APP_PATH . 'common' . DS . 'license' . DS;
        $codeFile   = $licenseDir . 'machine.id';
        $fingerprint = $this->getMachineFingerprint($os);

        if (!$isforce && is_file($cachePath)) {
            $cachedata = file_get_contents($cachePath);
            $encryData = openssl_decrypt($cachedata, 'AES-256-CBC', $enkey, 0, $iv);
            $cache     = json_decode($encryData, true);

            if (is_array($cache) && !empty($cache['exp']) && $cache['exp'] > time()) {
                $cacheCode = $cache['hash'] ?? ($cache['code'] ?? '');

                if ($cacheCode !== '') {
                    $cacheFingerprint = $cache['fingerprint'] ?? '';
                    $cacheMachineId   = $cache['machine_id'] ?? '';
                    if (
                        $cacheFingerprint === ''
                        || !hash_equals($cacheFingerprint, $fingerprint)
                        || strpos($cacheMachineId, 'mid-') !== 0
                    ) {
                        $cacheCode = '';
                    }
                }

                if ($cacheCode !== '') {
                    // machine.id 被误删时，从有效缓存恢复，避免缓存过期后授权码突变
                    if (!is_file($codeFile)) {
                        if (!is_dir($licenseDir)) {
                            @mkdir($licenseDir, 0755, true);
                        }
                        @file_put_contents($codeFile, $cacheMachineId, LOCK_EX);
                    }
                    return $cacheCode;
                }
            }
        }

        if (!is_dir($licenseDir) && !@mkdir($licenseDir, 0755, true)) {
            exception('创建授权目录失败，请检查目录权限');
        }

        $raw = '';
        if (!$isforce && is_file($codeFile)) {
            $raw = trim((string) file_get_contents($codeFile));
        }

        if ($raw === '' || strpos($raw, 'mid-') !== 0) {
            $raw = $this->createMachineId();

            if (file_put_contents($codeFile, $raw, LOCK_EX) === false) {
                exception('写入机器码文件失败，请检查授权目录权限');
            }
        }

        $code = strtolower($os) . '-' . hash('sha256', $raw . '|' . $fingerprint);

        if (!is_dir(RUNTIME_PATH)) {
            @mkdir(RUNTIME_PATH, 0755, true);
        }

        $encryData = openssl_encrypt(json_encode([
            'exp'         => time() + 86400 * 7,
            'hash'        => $code,
            'machine_id'  => $raw,
            'fingerprint' => $fingerprint,
        ]), 'AES-256-CBC', $enkey, 0, $iv);

        if ($encryData !== false) {
            @file_put_contents($cachePath, $encryData, LOCK_EX);
        }

        return $code;
    }

    /**
     * 生成本地持久化机器 ID
     * @return string
     */
    protected function createMachineId()
    {
        try {
            $random = bin2hex(random_bytes(16));
        } catch (\Throwable $th) {
            $random = md5(uniqid('', true) . mt_rand());
        }

        return 'mid-' . $random;
    }

    /**
     * 获取服务器环境指纹
     *
     * @param string $os
     * @return string
     */
    protected function getMachineFingerprint($os)
    {
        $items = [];

        if ($os === 'Windows') {
            // Windows 下不执行 wmic、powershell 等系统命令，避免被杀毒软件拦截。
            $items[] = (string) gethostname();
            $items[] = (string) getenv('COMPUTERNAME');
            $items[] = (string) getenv('USERDOMAIN');
            $items[] = (string) getenv('PROCESSOR_IDENTIFIER');

            // 如果希望换目录也失效，保留 ROOT_PATH；如果希望同机迁移目录不变码，可以删除这一行。
            $items[] = realpath(ROOT_PATH) ?: ROOT_PATH;
        } else {
            // Linux 优先读取系统 machine-id，正常独立服务器该值稳定且区分度高。
            foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $file) {
                if (is_file($file) && is_readable($file)) {
                    $value = trim((string) file_get_contents($file));
                    if ($value !== '') {
                        $items[] = $value;
                        break;
                    }
                }
            }

            // 再读取 DMI 硬件/虚拟机标识，增强普通复制和模板复制场景下的区分度。
            foreach (
                [
                    '/sys/class/dmi/id/product_uuid',
                    '/sys/class/dmi/id/board_serial',
                    '/sys/class/dmi/id/product_serial',
                    '/sys/class/dmi/id/chassis_serial',
                ] as $file
            ) {
                if (is_file($file) && is_readable($file)) {
                    $value = trim((string) file_get_contents($file));
                    if ($value !== '') {
                        $items[] = $value;
                    }
                }
            }

            // 极简环境下的兜底项，只有前面的系统标识都取不到时才使用。
            if (empty($items)) {
                $items[] = (string) gethostname();
                $items[] = realpath(ROOT_PATH) ?: ROOT_PATH;
            }
        }

        $items = array_filter(array_map(function ($value) {
            $value = strtolower(trim((string) $value));
            return preg_replace('/\s+/', ' ', $value);
        }, $items), function ($value) {
            return !in_array($value, [
                '',
                'none',
                'null',
                'nil',
                'n/a',
                'na',
                'unknown',
                'undefined',
                'default',
                'default string',
                'system serial number',
                'to be filled by o.e.m.',
                'to be filled by oem',
                '00000000-0000-0000-0000-000000000000',
                'ffffffff-ffff-ffff-ffff-ffffffffffff',
            ], true);
        });

        $items = array_values(array_unique($items));
        sort($items);

        if (empty($items)) {
            exception('获取服务器指纹失败，请确认服务器环境信息可读');
        }

        return hash('sha256', implode('|', $items));
    }

    /**
     * 授权文件验证
     */
    public function checklicense()
    {
        try {
            $licPath = self::$licensepath;
            $pemFile = APP_PATH . "common" . DS . "license" . DS . "public.pem";

            if (!is_file($licPath) || !is_readable($licPath)) {
                return self::setError('授权文件不存在或不可读，请联系服务商~');
            }

            if (!is_file($pemFile) || !is_readable($pemFile)) {
                return self::setError('公钥不存在或不可读，请联系服务商~');
            }

            $licenseContent = trim((string) file_get_contents($licPath));
            $pemContent     = trim((string) file_get_contents($pemFile));

            if ($licenseContent === '' || $pemContent === '') {
                return self::setError('授权文件或公钥内容为空~');
            }

            $licenseJson = base64_decode($licenseContent, true);
            if ($licenseJson === false) {
                return self::setError('授权文件编码错误~');
            }

            $license = json_decode($licenseJson, true);
            if (!is_array($license) || empty($license['data']) || empty($license['sign'])) {
                return self::setError('授权文件格式错误~');
            }

            $base64Data = (string) $license['data'];
            $signature  = base64_decode((string) $license['sign'], true);
            if ($signature === false || $signature === '') {
                return self::setError('授权签名格式错误~');
            }

            $publicKey = openssl_pkey_get_public($pemContent);
            if (!$publicKey) {
                return self::setError('公钥加载失败~');
            }

            $verify = openssl_verify($base64Data, $signature, $publicKey, OPENSSL_ALGO_SHA256);

            // PHP 8 下 openssl_pkey_get_public 返回对象，不需要强制释放；保留资源类型兼容旧版本
            if (is_resource($publicKey)) {
                openssl_free_key($publicKey);
            }

            if ($verify !== 1) {
                return self::setError('授权签名验证失败~');
            }

            $json = base64_decode($base64Data, true);
            if ($json === false) {
                return self::setError('授权数据编码错误~');
            }

            $data = json_decode($json, true);
            if (!is_array($data)) {
                return self::setError('授权数据格式错误~');
            }

            $uuid   = trim((string) ($data['uuid'] ?? ''));
            $expiry = $data['expiry'] ?? '';
            $system = trim((string) ($data['system'] ?? ''));

            if ($uuid === '' || $expiry === '' || $system === '') {
                return self::setError('授权数据不完整~');
            }

            $localUuid = md5($this->getcode());
            if (!hash_equals($localUuid, $uuid)) {
                return self::setError('授权无效：授权码异常~');
            }

            $configType = trim((string) ($this->cloudconfig['type'] ?? ''));
            if ($configType === '' || !hash_equals($configType, $system)) {
                return self::setError('授权无效：系统类型异常~');
            }

            // 兼容时间戳和日期字符串两种授权过期时间格式
            $expiryTime = is_numeric($expiry) ? (int) $expiry : strtotime((string) $expiry);
            if (!$expiryTime) {
                return self::setError('授权有效期格式错误~');
            }

            if ($expiryTime < time()) {
                return self::setError('授权已过期，请联系服务商~');
            }

            $data['expiry'] = $expiryTime;
            return $data;
        } catch (\Throwable $th) {
            return self::setError('授权验证出错~');
        }
    }

    /**
     * 检测授权设备数量
     */
    public function checkdev($allownum)
    {
        $path = request()->path();
        if ($path == 'product/device/add/source/sub' || stripos($path, 'device/add/source/sub') !== false) {
            $totalNum = Db::name('device')->where('node_type', '<>', 1)->count();
            if ($allownum >= 0 && $totalNum >= $allownum) {
                exception("当前设备数量已达到授权上限[{$allownum}],无法继续添加");
            }
        }
    }

    /**
     * 发送请求
     */
    public function sendRequest($url, $params = [], $method = 'post', $auth = true)
    {
        $baseurl = trim($this->cloudconfig['url'] ?? '');
        if (empty($baseurl)) {
            exception('请先配置云端地址');
        }

        if (!preg_match('/^https?:\/\//i', $baseurl)) {
            $baseurl = 'http://' . $baseurl;
        }

        $token = '';
        if ($auth) {
            if (!is_file(self::$authpath) || !$token = file_get_contents(self::$authpath)) {
                exception('接口token异常，请重新获取');
            }
        }

        $json = [];
        try {
            $options = [
                'timeout'         => 3,
                'connect_timeout' => 3,
                'verify'          => false,
                'http_errors'     => false,
                'headers'         => [
                    'Referer'    => dirname(request()->root(true)),
                    'User-Agent' => 'HYIOT',
                ],
            ];
            $client = new Client($options);
            $method = strtolower($method);

            $params['token'] = $token;
            $options         = $method == 'post' ? ['form_params' => $params] : ['query' => $params];

            // 统一处理斜杠，避免 baseurl 带 / 或 url 不带 / 时拼接异常
            $requestUrl = rtrim($baseurl, '/') . '/' . ltrim($url, '/');
            $response   = $client->request($method, $requestUrl, $options);

            $body    = $response->getBody();
            $content = $body->getContents();
            $json    = $content;
            if ($method == 'post') {
                $json = (array) json_decode($content, true);
            }
        } catch (TransferException $e) {
            exception('网络异常~');
        } catch (\Exception $e) {
            exception('请求异常' . $e->getMessage());
        }

        return $json;
    }

    /**
     * 设置错误信息
     * @param string|null $error
     * @return bool
     */
    protected static function setError(?string $error = null)
    {
        self::$error = $error ?: '未知错误';
        return false;
    }

    /**
     * 获取错误信息
     * @return string
     */
    public static function getError()
    {
        $error       = self::$error;
        self::$error = null;
        return $error;
    }
}
