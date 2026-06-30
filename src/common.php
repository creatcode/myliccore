<?php

\think\Console::addDefaultCommands([
    'safeaccess\command\LiccoreReset',
]);

\think\Hook::add('app_begin', function () {
    if (!is_file(APP_PATH . 'common/license/register.lock') && is_file(__DIR__ . '/register.php')) {
        copy(__DIR__ . '/register.php', ROOT_PATH . 'public/register.php');
        // unlink(__DIR__ . '/register.php');
        header("location:/register.php");
        exit;
    }

    $cloudConfig = \think\Config::get('cloud');

    if (!is_array($cloudConfig) || !isset($cloudConfig['auth_check']) || $cloudConfig['auth_check'] == 0) {
        return;
    }

    try {
        if (!class_exists('\safeaccess\CloudService')) {
            exception('授权类异常');
        }
        $res = \safeaccess\CloudService::init()->checkAuth();
        \think\Request::instance()->bind('licdata', $res);
    } catch (\Throwable $th) {
        $type = 'html';
        $template = \think\Config::get('template');
        $view = \think\Config::get('view_replace_str');
        \think\Lang::set('Warning', '授权警告~');
        // APP_PATH . 'common' . DS . 'view' . DS . 'tpl' . DS . 'dispatch_jump.tpl'
        $result = \think\View::instance($template, $view)
            ->fetch(__DIR__ . '/dispatch_jump.tpl', [
                'code' => 0,
                'msg'  => $th->getMessage(),
                'data' => '',
                'url'  => '',
                'wait' => 3,
            ]);

        $basepath = explode('/', request()->path())[0] ?? '';
        if ($basepath == 'api' || $basepath == 'adminapi' || request()->isAjax()) {
            $result = [
                'code' => 407,
                'msg'  => $th->getMessage(),
                'time' => \think\Request::instance()->server('REQUEST_TIME'),
                'data' => '',
            ];
            $type = 'json';
        }

        $response = \think\Response::create($result, $type)->header([]);
        abort($response);
    }
});
