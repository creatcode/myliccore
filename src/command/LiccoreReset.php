<?php

namespace safeaccess\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;

class LiccoreReset extends Command
{
    protected function configure()
    {
        $this->setName('sitereset')
            ->setDescription('Clear the site registration status for re registration');
    }

    protected function execute(Input $input, Output $output)
    {
        $licenseDir = ROOT_PATH . 'application/common/license/';
        $files = [
            $licenseDir . 'register.lock',
            $licenseDir . 'license.lic',
            $licenseDir . 'public.pem',
        ];

        // 清理机器码运行缓存，避免继续读取旧缓存
        $files[] = RUNTIME_PATH . md5(PHP_OS_FAMILY . '_machine_code') . '.dat';

        $clearMachineId = false;
        $clearMachineId = $output->confirm($input, '是否同时清除机器码文件 machine.id？清除后需要重新生成授权码 [y/N]', false);
        if ($clearMachineId) {
            $files[] = $licenseDir . 'machine.id';
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
                $output->writeln('<info>已删除：</info>' . $file);
            }
        }

        if (!$clearMachineId) {
            $output->writeln('<comment>已保留 machine.id，重新注册时会沿用原机器码。</comment>');
        }

        $output->writeln('<comment>授权文件已清除，插件包会在下次访问时自动恢复注册页。</comment>');
    }
}
