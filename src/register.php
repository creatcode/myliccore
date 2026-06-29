<?php
require_once "../vendor/creatcode/liccore/src/CloudService.php";
// require_once "../vendor/autoload.php";
require_once "../thinkphp/library/think/Config.php";

/**
 * 发送 CURL 请求
 *
 * @param string $url 请求地址
 * @param array $params 请求参数
 * @param string $method 请求方式
 * @param int $timeout 超时时间
 * @return string
 * @throws Exception
 */
function curl_request(string $url, array $params = [], string $method = 'POST', int $timeout = 10): string
{
    $method = strtoupper($method);
    $ch = curl_init();

    if ($method === 'GET' && !empty($params)) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
    }

    $headers = [
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: SiteAuthorization',
    ];

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('请求授权服务失败：' . ($error ?: '网络异常'));
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        throw new Exception('授权服务响应异常，HTTP状态码：' . $statusCode);
    }

    return $response;
}

$method = $_SERVER['REQUEST_METHOD'];
$lockFile = "../application/common/license/register.lock";
$licenseFile = "../application/common/license/license.lic";
$pemFile = "../application/common/license/public.pem";
if (!is_dir("../application/common/license/")) {
    mkdir("../application/common/license/", 0755, true);
}
if (is_file($lockFile)) {
    exit('<title>站点注册</title><div style="text-align:center;margin-top:300px;font-size:20px;">此站点已注册</div>');
}
$config = include "../application/extra/cloud.php";

$cloudUrl = trim($config['url'] ?? '');
if ($cloudUrl !== '' && !preg_match('/^https?:\/\//i', $cloudUrl)) {
    $cloudUrl = 'http://' . $cloudUrl;
}
$cloudUrl = rtrim($cloudUrl, '/');
$requiredConfig = ['url', 'version'];
foreach ($requiredConfig as $key) {
    if (empty($config[$key])) {
        $msgcontent = "请先在配置文件 application/extra/cloud.php 中填写完整的配置信息再进行操作";
        echo <<<EOF
    <style>
    .fullscreen-mask {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.8);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .mask-message {
        background: #fff;
        color: #000;
        padding: 20px 30px;
        border-radius: 8px;
        font-size: 18px;
        box-shadow: 0 0 10px rgba(0,0,0,0.3);
        font-family: "Microsoft Yahei", sans-serif;
    }
    </style>
    
    <div class="fullscreen-mask">
        <div class="mask-message">$msgcontent</div>
    </div>
    EOF;
        break;
    }
}
if ($method == 'POST') {
    try {
        define('DS', DIRECTORY_SEPARATOR);
        defined('APP_PATH') or define('APP_PATH', dirname($_SERVER['SCRIPT_FILENAME']) . DS);
        defined('ROOT_PATH') or define('ROOT_PATH', dirname(realpath(APP_PATH)) . DS);
        defined('RUNTIME_PATH') or define('RUNTIME_PATH', ROOT_PATH . 'runtime' . DS);

        $os    = PHP_OS_FAMILY;
        $filename     = md5("{$os}_machine_code") . ".dat";
        $cachePath    = RUNTIME_PATH . "{$filename}";
        @unlink($cachePath);
        // if ($os === 'Windows') {
        //     // 清除机器ID文件
        //     $machineIdFile = APP_PATH . 'common' . DS . 'license' . DS . 'machine.id';
        //     is_file($machineIdFile) && @unlink($machineIdFile);
        // }

        $url = $cloudUrl . '/api/index/site_reg';
        $params['name'] = $_POST['name'];
        $authCheck = isset($_POST['auth_check']) && (int) $_POST['auth_check'] === 1 ? 1 : 0;
        $period = trim($_POST['period'] ?? '');
        if ($authCheck === 1) {
            if ($period === '') {
                throw new Exception('请选择授权有效期');
            }

            // 原生 date 只提交日期，这里统一补齐到当天结束时间
            $params['period'] = $period . ' 23:59:59';
        } else {
            $params['period'] = '2999-12-31 23:59:59';
        }
        $params['auth_check'] = $authCheck;
        $env = $_POST['env'] ?? ($config['env'] ?? 'local');
        $env = in_array($env, ['local', 'online'], true) ? $env : 'local';
        $projectId = trim($_POST['project_id'] ?? '');
        if ($projectId === '') {
            throw new Exception('请选择项目类型');
        }

        $params['devnum'] = $_POST['devnum'] ?? '-1';
        $params['version'] = $config['version'];
        $params['project_id'] = $projectId;
        $params['type'] = $env;
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $fullDomain = $protocol . '://' . $host;
        $params['url'] = $fullDomain;

        $machineCode = \safeaccess\CloudService::init()->getcode();
        if (empty($machineCode)) {
            throw new Exception('获取设备编码失败');
        }

        $params['code'] = $machineCode;
        $responseBody = curl_request($url, $params, 'POST');
        $response = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($response)) {
            throw new Exception('授权服务返回格式异常');
        }

        if (($response['code'] ?? 0) != 1) {
            throw new Exception('授权失败：' . ($response['msg'] ?? '未知错误'));
        }

        $data = $response['data'] ?? [];

        if ($authCheck === 1) {
            if (empty($data['secret_key']) || empty($data['license']) || empty($data['pem'])) {
                throw new Exception('授权服务返回数据不完整');
            }
            // 启用授权验证时，写入授权文件
            file_put_contents($lockFile, $data['secret_key']);
            file_put_contents($licenseFile, $data['license']);
            file_put_contents($pemFile, $data['pem']);
        } else {
            // 关闭授权验证时，只写入注册标记，避免重复进入注册页
            file_put_contents($lockFile, !empty($data['secret_key']) ? $data['secret_key'] : 'auth_check_disabled-' . uniqid('', true));
            is_file($licenseFile) && @unlink($licenseFile);
            is_file($pemFile) && @unlink($pemFile);
        }

        if (empty($data['project_type'])) {
            throw new Exception('授权服务未返回项目类型');
        }

        // 保存云端确认后的项目类型、运行环境和授权校验开关
        $cloudConfig = include "../application/extra/cloud.php";
        $cloudConfig['type'] = $data['project_type'];
        $cloudConfig['env'] = $env;
        $cloudConfig['auth_check'] = $authCheck;
        file_put_contents("../application/extra/cloud.php", '<?php' . "\n\nreturn " . var_export($cloudConfig, true) . ";\n");

        //删除当前安装脚本
        @unlink(__FILE__);
    } catch (\Throwable $e) {
        exit(json_encode(['code' => 0, 'msg' => $e->getMessage()]));
    }
    exit(json_encode(['code' => 1, 'msg' => '注册成功']));
}
?>

<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>站点注册</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1">
    <meta name="renderer" content="webkit">
    <script src=".\assets\libs\jquery\dist\jquery.min.js"></script>
    <style>
        :root {
            --primary: #0891b2;
            --primary-dark: #0e7490;
            --success: #16a34a;
            --danger: #dc2626;
            --text: #164e63;
            --muted: #64748b;
            --border: #dbeafe;
            --card: rgba(255, 255, 255, 0.92);
            --shadow: 0 24px 70px rgba(8, 91, 126, 0.18);
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            padding: 48px 18px;
            line-height: 1.5;
            color: var(--text);
            background:
                radial-gradient(circle at 12% 18%, rgba(34, 211, 238, 0.34), transparent 28%),
                radial-gradient(circle at 86% 12%, rgba(8, 145, 178, 0.20), transparent 30%),
                linear-gradient(135deg, #ecfeff 0%, #f8fafc 48%, #e0f2fe 100%);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        body,
        input,
        select,
        button {
            font-family: "Microsoft Yahei", "PingFang SC", "Helvetica Neue", Arial, sans-serif;
            font-size: 14px;
        }

        a {
            color: var(--primary);
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .container {
            width: 100%;
            max-width: 520px;
            margin: 0 auto;
            padding: 34px;
            text-align: center;
            background: var(--card);
            border: 1px solid rgba(255, 255, 255, 0.72);
            border-radius: 18px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(18px);
        }

        h1 {
            width: 78px;
            height: 78px;
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 22px;
            background: linear-gradient(145deg, #ffffff, #cffafe);
            box-shadow: 0 14px 36px rgba(8, 145, 178, 0.20);
        }

        h1 svg {
            width: 56px;
            height: 56px;
        }

        h2 {
            margin: 0;
            font-size: 30px;
            font-weight: 700;
            letter-spacing: 1px;
            color: #0f3f56;
        }

        .page-desc {
            margin: 10px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        form {
            margin-top: 30px;
            text-align: left;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-field {
            position: relative;
        }

        .form-field label {
            display: block;
            margin-bottom: 8px;
            color: #22576b;
            font-size: 13px;
            font-weight: 700;
        }

        .form-field input,
        .custom-select {
            width: 100%;
            height: 50px;
            margin: 0;
            padding: 0 44px 0 15px;
            color: #0f172a;
            border: 1px solid var(--border);
            border-radius: 10px;
            background-color: #fff;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }

        .form-field input:hover,
        .custom-select:hover {
            border-color: #93c5fd;
        }

        .form-field input:focus,
        .custom-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(8, 145, 178, 0.12);
        }

        .custom-select {
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg width='14' height='14' viewBox='0 0 14 14' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M3.2 5.1L7 8.9l3.8-3.8' fill='none' stroke='%230891B2' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            background-size: 14px;
        }

        .form-field span {
            display: inline-block;
            margin-top: 8px;
            color: var(--muted);
            font-size: 12px;
        }

        button,
        .btn {
            width: 100%;
            min-height: 50px;
            padding: 0 28px;
            color: #fff;
            border: 0;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            letter-spacing: 1px;
            background: linear-gradient(135deg, var(--primary), var(--success));
            box-shadow: 0 14px 30px rgba(8, 145, 178, 0.24);
            transition: transform 0.2s, box-shadow 0.2s, opacity 0.2s;
            -webkit-appearance: none;
        }

        button:hover,
        .btn:hover {
            text-decoration: none;
            transform: translateY(-1px);
            box-shadow: 0 18px 36px rgba(8, 145, 178, 0.30);
        }

        button:focus-visible,
        .btn:focus-visible {
            outline: 3px solid rgba(8, 145, 178, 0.24);
            outline-offset: 3px;
        }

        button[disabled] {
            cursor: not-allowed;
            opacity: 0.62;
            transform: none;
            box-shadow: none;
        }

        .form-buttons {
            margin-top: 24px;
            min-height: 50px;
            line-height: normal;
        }

        #error,
        .error,
        #success,
        .success,
        #warmtips,
        .warmtips {
            margin-bottom: 18px;
            padding: 13px 15px;
            border-radius: 10px;
            line-height: 1.6;
        }

        #error,
        .error {
            color: #991b1b;
            background: #fee2e2;
            border: 1px solid #fecaca;
        }

        #success,
        .success {
            color: #166534;
            background: #dcfce7;
            border: 1px solid #bbf7d0;
        }

        #warmtips,
        .warmtips {
            color: #b45309;
            background: #fffbeb;
            border: 1px solid #fde68a;
        }

        #error a,
        .error a {
            color: #991b1b;
            text-decoration: underline;
        }

        @media (max-width: 520px) {
            body {
                padding: 24px 12px;
            }

            .container {
                padding: 26px 18px;
                border-radius: 14px;
            }

            h2 {
                font-size: 26px;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                transition: none !important;
            }
        }

        .date-row {
            display: flex;
            gap: 10px;
        }

        .date-row input {
            flex: 1;
        }

        .permanent-btn {
            width: 82px;
            min-height: 50px;
            padding: 0;
            flex: 0 0 82px;
            background: #0f766e;
            box-shadow: none;
        }

        #hehe {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 50px;
            margin-top: 4px;
            color: #fff;
            border-radius: 10px;
            background: linear-gradient(135deg, #0891b2, #16a34a) !important;
            box-shadow: 0 14px 30px rgba(8, 145, 178, 0.24);
        }

        #hehe:hover {
            color: #fff;
            text-decoration: none;
            transform: translateY(-1px);
            box-shadow: 0 18px 36px rgba(8, 145, 178, 0.30);
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>
            <svg version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" width="80px" height="96px" viewBox="0 0 64 64" enable-background="new 0 0 64 64" xml:space="preserve">
                <image id="image0" width="64" height="64" x="0" y="0"
                    xlink:href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEAAAABABAMAAABYR2ztAAAAIGNIUk0AAHomAACAhAAA+gAAAIDo
AAB1MAAA6mAAADqYAAAXcJy6UTwAAAAeUExURQAAANcgCNcdBdgdBdcgCNkeBtgeBtceBtgeBv//
/3haf5gAAAAIdFJOUwAgYL9An9+AZHoGogAAAAFiS0dECfHZpewAAAAHdElNRQfpBAgFJxAMEg4v
AAABg0lEQVRIx9WVPW/CMBCGndSUjhk7hlRCnRGqGEFVKCNUhGYtSzOWBXVOGsjPrmXfJf7IuVKV
pe+Uyz0++474hbGhFfnT4aJa+fJpI7Sj8w+N1IzYJswbULXszRdNq2pClkfN7W0OmME6HwYRLrB2
3O6k95vi8los422xnbP9q4qfMYZ+N86KF9ymlOEjlNea508ucDROHZxtYO/8LIUOVLE7OZ4kyQSB
0vsNDAWkWaYfNMxAMQJWnRFOb/ufAX0+FpC8rbzAWpQ9eYAb+b1GNPAlT/ZOAoE6+pUEsLuIAu4A
iCngHoBPCth08/tjhVsAlhQwVvkLPSh1K79/m+S2B1irkBc4SBsQriDDUYH3XAfkkvQowwCtJ9dv
t/SSwPigpub1v0bWfcttAzHdleeuBenuiraoAHTGywnzY3iDvsKBb+YqRtvsbK31TunQaJu1fu4p
vBRed8ZHsyt01zqHh37b69TzvzTWiZj1qG3GcGWDOPjzQrKDMmK0RDMz5lW6Z4PrB+cAkBWPxSLd
AAAAJXRFWHRkYXRlOmNyZWF0ZQAyMDI1LTA0LTA4VDA1OjM5OjE2KzAwOjAwCszI4wAAACV0RVh0
ZGF0ZTptb2RpZnkAMjAyNS0wNC0wOFQwNTozOToxNiswMDowMHuRcF8AAAAodEVYdGRhdGU6dGlt
ZXN0YW1wADIwMjUtMDQtMDhUMDU6Mzk6MTYrMDA6MDAshFGAAAAAAElFTkSuQmCC" />
            </svg>
        </h1>
        <h2>站点注册</h2>
        <div>

            <form method="post">
                <div id="error" style="display:none"></div>
                <div id="success" style="display:none"></div>

                <div class="form-group">
                    <div class="form-field">
                        <label>项目类型</label>
                        <select name="project_id" class="custom-select" required id="make">
                            <option value="">----- 请选择项目类型 -----</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <div class="form-field">
                        <label>运行环境</label>
                        <select name="env" class="custom-select" required>
                            <option value="local">本地</option>
                            <option value="online">线上</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <div class="form-field">
                        <label>站点名称</label>
                        <input type="text" name="name" value="" required="">
                    </div>
                </div>
                <div class="form-group">
                    <div class="form-field">
                        <label>授权验证</label>
                        <select name="auth_check" class="custom-select" required id="auth-check">
                            <option value="1">启用授权验证</option>
                            <option value="0" selected>关闭授权验证</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" id="period-group" style="display:none">
                    <div class="form-field">
                        <label>授权有效期</label>
                        <div class="date-row">
                            <input type="date" name="period" value="" required id="ID-laydate-demo" max="2999-12-31">
                            <button type="button" class="permanent-btn" id="set-permanent">永久</button>
                        </div>
                    </div>
                </div>
                <div class="form-group" id="devnum-group" style="display:none">
                    <div class="form-field">
                        <label>授权设备数</label>
                        <input type="text" name="devnum" value="-1">
                        <span>tips:-1表示不限制</span>
                    </div>
                </div>

                <div class="form-buttons">
                    <button type="submit">提 交</button>
                </div>
            </form>

            <script>
                let baseurl = "<?php echo $cloudUrl ?>";

                // 获取项目数据
                function callback(data) {
                    if (!$.isArray(data) || data.length === 0) {
                        $('#error').show().text('获取项目类型失败，请检查云端地址配置');
                        return;
                    }

                    data.forEach(function(item) {
                        $('#make').append(
                            '<option value="' + item.type + '" data-type="' + item.type + '">' + item.name + '</option>'
                        );
                    });

                    $('#error').hide();
                    $('#make').trigger('change');
                }

                $.ajax({
                    url: baseurl + '/api/index/getproject',
                    type: "GET",
                    dataType: "jsonp",
                    jsonpCallback: "callback",
                    timeout: 5000,
                    error: function() {
                        $('#error').show().text('获取项目类型失败，请检查云端地址或网络连接');
                    }
                });

                $(function() {
                    $('form :input:first').select();

                    function togglePeriod() {
                        var isAuthCheck = $('#auth-check').val() === '1';
                        var $period = $('#ID-laydate-demo');

                        if (isAuthCheck) {
                            $('#period-group').show();
                            $period.prop('required', true);

                            // 重新启用授权校验时，清空隐藏状态下的默认值，避免用户误提交
                            if ($period.val() === '2999-12-31') {
                                $period.val('');
                            }
                        } else {
                            $('#period-group').hide();
                            $period.prop('required', false).val('');
                        }
                    }

                    $('#auth-check').on('change', togglePeriod);
                    togglePeriod();

                    $('#set-permanent').on('click', function() {
                        // 原生 date 只能保存日期，后端会统一补齐为 2999-12-31 23:59:59
                        $('#ID-laydate-demo').val('2999-12-31');
                    });

                    function toggleDevnum() {
                        var projectType = $('#make option:selected').data('type');
                        var isIotadmin = projectType === 'iotadmin';

                        $('#devnum-group').toggle(isIotadmin);
                        $('#devnum-group input[name="devnum"]').prop('required', isIotadmin);
                    }

                    $('#make').on('change', toggleDevnum);
                    toggleDevnum();

                    $('form').on('submit', function(e) {
                        e.preventDefault();
                        var form = this;
                        var $error = $("#error");
                        var $success = $("#success");
                        var $button = $(this).find('button[type="submit"]')
                            .text("提交中...")
                            .prop('disabled', true);

                        var $sub_buttons = $(".form-buttons", form);

                        $.ajax({
                            url: "",
                            type: "POST",
                            dataType: "json",
                            data: $(this).serialize(),
                            success: function(ret) {
                                if (ret.code == 1) {
                                    var data = ret.data;
                                    $error.hide();
                                    $(".form-group", form).remove();
                                    $button.remove();
                                    $("#success").text(ret.msg).show();
                                    $("<a class='btn' id='hehe' href='/'>返回主页</a>").appendTo($sub_buttons);
                                } else {
                                    $error.show().text(ret.msg);
                                    $button.prop('disabled', false).text("重新提交");
                                    $("html,body").animate({
                                        scrollTop: 0
                                    }, 500);
                                }
                            },
                            error: function(xhr) {
                                $error.show().text(xhr.responseText);
                                $button.prop('disabled', false).text("重新提交");
                                $("html,body").animate({
                                    scrollTop: 0
                                }, 500);
                            }
                        });
                        return false;
                    });
                });
            </script>
        </div>
    </div>
</body>

</html>