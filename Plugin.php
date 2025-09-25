<?php
/**
 * 评论邮件提醒插件
 *
 * @package CommentToMail 2025 PHP8兼容版
 * @author Mxucc.
 * @version 2.0.1
 * @link https://moexc.com
 * @oriAuthor DEFE (http://defe.me)
 * 
 * 原作者是 DEFE (http://defe.me),请尊重版权
 *
 */
class CommentToMail_Plugin implements Typecho_Plugin_Interface
{
    /** @var string 提交路由前缀 */
    public static $action = 'comment-to-mail';
    
    /** @var string 控制菜单链接 */
    public static $panel  = 'CommentToMail/page/console.php';

    /** @var bool 是否记录日志 */
    private static $_isMailLog  = false;
    
    /** @var bool 请求适配器 */
    private static $_adapter    = false;

    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        if (false == self::isAvailable()) {
            throw new Typecho_Plugin_Exception(_t('对不起, 您的主机没有打开 allow_url_fopen 功能而且不支持 php-curl 扩展, 无法正常使用此功能'));
        }
        
        if (false == self::isWritable(dirname(__FILE__) . '/cache/')) {
            throw new Typecho_Plugin_Exception(_t('对不起，插件目录不可写，无法正常使用此功能'));
        }

        Typecho_Plugin::factory('Widget_Feedback')->finishComment = array('CommentToMail_Plugin', 'parseComment');
        Typecho_Plugin::factory('Widget_Comments_Edit')->finishComment = array('CommentToMail_Plugin', 'parseComment');
        Helper::addAction(self::$action, 'CommentToMail_Action');
        Helper::addPanel(1, self::$panel, '评论邮件提醒', '评论邮件提醒控制台', 'administrator');

        return _t('请设置邮箱信息，以使插件正常使用！');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        Helper::removeAction(self::$action);
        Helper::removePanel(1, self::$panel);
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $mode= new Typecho_Widget_Helper_Form_Element_Radio('mode',
                array( 'smtp' => 'smtp',
                       'mail' => 'mail()',
                       'sendmail' => 'sendmail()'),
                'smtp', '发信方式');
        $form->addInput($mode);

        $host = new Typecho_Widget_Helper_Form_Element_Text('host', NULL, 'smtp.',
                _t('SMTP地址'), _t('请填写 SMTP 服务器地址'));
        $form->addInput($host->addRule('required', _t('必须填写一个SMTP服务器地址')));

        $port = new Typecho_Widget_Helper_Form_Element_Text('port', NULL, '25',
                _t('SMTP端口'), _t('SMTP服务端口,一般为25。'));
        $port->input->setAttribute('class', 'mini');
        $form->addInput($port->addRule('required', _t('必须填写SMTP服务端口'))
                ->addRule('isInteger', _t('端口号必须是纯数字')));

        $user = new Typecho_Widget_Helper_Form_Element_Text('user', NULL, NULL,
                _t('SMTP用户'),_t('SMTP服务验证用户名,一般为邮箱名如：youname@domain.com'));
        $form->addInput($user->addRule('required', _t('SMTP服务验证用户名')));

        $pass = new Typecho_Widget_Helper_Form_Element_Password('pass', NULL, NULL,
                _t('SMTP密码'));
        $form->addInput($pass->addRule('required', _t('SMTP服务验证密码')));

        $validate = new Typecho_Widget_Helper_Form_Element_Checkbox('validate',
                array('validate'=>'服务器需要验证',
                    'ssl'=>'ssl加密'),
                array('validate'),'SMTP验证');
        $form->addInput($validate);
        
        $fromName = new Typecho_Widget_Helper_Form_Element_Text('fromName', NULL, NULL,
                _t('发件人名称'),_t('发件人名称，留空则使用博客标题'));
        $form->addInput($fromName);

        $mail = new Typecho_Widget_Helper_Form_Element_Text('mail', NULL, NULL,
                _t('接收邮件的地址'),_t('接收邮件的地址,如为空则使用文章作者个人设置中的邮件地址！'));
        $form->addInput($mail->addRule('email', _t('请填写正确的邮件地址！')));

        $contactme = new Typecho_Widget_Helper_Form_Element_Text('contactme', NULL, NULL,
                _t('模板中“联系我”的邮件地址'),_t('联系我用的邮件地址,如为空则使用文章作者个人设置中的邮件地址！'));
        $form->addInput($contactme->addRule('email', _t('请填写正确的邮件地址！')));

        $status = new Typecho_Widget_Helper_Form_Element_Checkbox('status',
                array('approved' => '提醒已通过评论',
                        'waiting' => '提醒待审核评论',
                        'spam' => '提醒垃圾评论'),
                array('approved', 'waiting'), '提醒设置',_t('该选项仅针对博主，访客只发送已通过的评论。'));
        $form->addInput($status);

        $other = new Typecho_Widget_Helper_Form_Element_Checkbox('other',
                array('to_owner' => '有评论及回复时，发邮件通知博主。',
                    'to_guest' => '评论被回复时，发邮件通知评论者。',
                    'to_me'=>'自己回复自己的评论时，发邮件通知。(同时针对博主和访客)',
                    'to_log' => '记录邮件发送日志。'),
                array('to_owner','to_guest'), '其他设置',_t('选中该选项插件会在log/mailer_log.txt 文件中记录发送日志。'));
        $form->addInput($other->multiMode());

        $titleForOwner = new Typecho_Widget_Helper_Form_Element_Text('titleForOwner',null,"[{title}] 一文有新的评论",
                _t('博主接收邮件标题'));
        $form->addInput($titleForOwner->addRule('required', _t('博主接收邮件标题 不能为空')));

        $titleForGuest = new Typecho_Widget_Helper_Form_Element_Text('titleForGuest',null,"您在 [{title}] 的评论有了回复",
                _t('访客接收邮件标题'));
        $form->addInput($titleForGuest->addRule('required', _t('访客接收邮件标题 不能为空')));
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {}

    /**
     * 获取邮件内容
     *
     * @access public
     * @param $comment 调用参数
     * @return void
     */
    public static function parseComment($comment)
    {        
        try {
            $options = Typecho_Widget::widget('Widget_Options');
            $cfg = array(
                'siteTitle' => $options->title,
                'timezone'  => $options->timezone,
                'cid'       => $comment->cid,
                'coid'      => $comment->coid,
                'created'   => $comment->created,
                'author'    => $comment->author,
                'authorId'  => $comment->authorId,
                'ownerId'   => $comment->ownerId,
                'mail'      => $comment->mail,
                'ip'        => $comment->ip,
                'title'     => $comment->title,
                'text'      => $comment->text,
                'permalink' => $comment->permalink,
                'status'    => $comment->status,
                'parent'    => $comment->parent,
                'manage'    => $options->siteUrl . "admin/manage-comments.php"
            );

            $pluginConfig = Helper::options()->plugin('CommentToMail');
            if (!$pluginConfig) {
                self::saveLog("插件配置不存在，无法处理评论\n");
                return;
            }
            
            self::$_isMailLog = isset($pluginConfig->other) && is_array($pluginConfig->other) && in_array('to_log', $pluginConfig->other);
// 版本戳，便于确认部署生效
self::saveLog("CommentToMail Plugin 版本戳: v2025-09-24-DBG2\n");

            //是否接收邮件
            if (isset($_POST['banmail']) && 'stop' == $_POST['banmail']) {
                $cfg['banMail'] = 1;
            } else {
                $cfg['banMail'] = 0;
            }

            $fileName = Typecho_Common::randString(7);
            $cfg = (object)$cfg;
            
            // 确保缓存目录存在
            $cacheDir = dirname(__FILE__) . '/cache/';
            if (!is_dir($cacheDir)) {
                if (!@mkdir($cacheDir, 0755, true)) {
                    self::saveLog("无法创建缓存目录: {$cacheDir}\n");
                    return;
                }
            }
            
            // 检查缓存目录是否可写
            if (!is_writable($cacheDir)) {
                self::saveLog("缓存目录不可写: {$cacheDir}\n");
                return;
            }
            
            $cacheFile = $cacheDir . $fileName;
            $serializedData = serialize($cfg);
            
            // 写入缓存文件，增加重试机制和详细错误检查
            $maxRetries = 5;
            $retryCount = 0;
            $writeSuccess = false;
            $lastError = '';
            
            // 先检查序列化数据是否有效
            if (empty($serializedData)) {
                self::saveLog("序列化数据为空，无法创建缓存文件\n");
                return;
            }
            
            self::saveLog("准备写入缓存文件: {$cacheFile}, 数据大小: " . strlen($serializedData) . " bytes\n");
            
            while ($retryCount < $maxRetries && !$writeSuccess) {
                $retryCount++;
                
                // 清除之前可能存在的文件
                if (file_exists($cacheFile)) {
                    @unlink($cacheFile);
                }
                
                $bytesWritten = @file_put_contents($cacheFile, $serializedData, LOCK_EX);
                
                if ($bytesWritten !== false && $bytesWritten > 0) {
                    // 立即验证文件是否真的存在且可读
                    if (file_exists($cacheFile) && is_readable($cacheFile)) {
                        $actualSize = filesize($cacheFile);
                        if ($actualSize == strlen($serializedData)) {
                            $writeSuccess = true;
                            self::saveLog("缓存文件写入成功，第{$retryCount}次尝试，写入{$bytesWritten}字节\n");
                        } else {
                            $lastError = "文件大小不匹配，期望: " . strlen($serializedData) . ", 实际: {$actualSize}";
                        }
                    } else {
                        $lastError = "文件写入后不存在或不可读";
                    }
                } else {
                    $lastError = "file_put_contents返回false或0，可能的错误: " . error_get_last()['message'];
                }
                
                if (!$writeSuccess && $retryCount < $maxRetries) {
                    self::saveLog("第{$retryCount}次写入失败: {$lastError}，等待后重试\n");
                    usleep(200000); // 等待200毫秒后重试
                }
            }
            
            if (!$writeSuccess) {
                self::saveLog("缓存文件写入失败，已重试{$maxRetries}次，最后错误: {$lastError}\n");
                self::saveLog("目录权限: " . substr(sprintf('%o', fileperms($cacheDir)), -4) . "\n");
                self::saveLog("磁盘空间: " . disk_free_space($cacheDir) . " bytes\n");
                return;
            }
            
            // 设置文件权限，确保可读
            @chmod($cacheFile, 0644);
            
            $fileSize = filesize($cacheFile);
            self::saveLog("缓存文件最终创建成功: {$cacheFile}, 大小: {$fileSize} bytes\n");
            
            // 写入后回读并验证反序列化，确保 PHP8 下数据可靠
            $readBack = @file_get_contents($cacheFile);
            if ($readBack === false) {
                self::saveLog("写入后回读失败: {$cacheFile}\n");
                return;
            }
            
            $readObj = @unserialize($readBack);
            if ($readObj === false || !is_object($readObj)) {
                self::saveLog("写入后反序列化验证失败: {$cacheFile}，内容大小: " . strlen($readBack) . " bytes\n");
                // 清理不可靠的缓存文件，避免Action处理异常
                @unlink($cacheFile);
                return;
            }
            self::saveLog("缓存文件反序列化验证通过: {$cacheFile}\n");
            
            // 添加延迟，确保文件系统同步，特别是在高并发环境下
            usleep(300000); // 等待300毫秒
            
            $url = ($options->rewrite) ? $options->siteUrl : $options->siteUrl . 'index.php';
            $url = rtrim($url, '/') . '/action/' . self::$action . '?send=' . $fileName;

            $date = new Typecho_Date(Typecho_Date::gmtTime());
            $time = $date->format('Y-m-d H:i:s');
            
            self::saveLog("{$time} 开始发送请求：{$url}\n");
            self::asyncRequest($url);
            
        } catch (Exception $e) {
            self::saveLog("处理评论时发生异常: " . $e->getMessage() . "\n");
        } catch (Error $e) {
            self::saveLog("处理评论时发生致命错误: " . $e->getMessage() . "\n");
        }
    }



    /**
     * 发送异步请求
     * @param $url
     */
    public static function asyncRequest($url)
    {
        $adapter = self::isAvailable();
        self::saveLog($adapter . " 方式发送\n");
        
        try {
            $result = self::$_adapter == 'Socket' ? self::socket($url) : self::curl($url);
            if ($result === false) {
                self::saveLog("异步请求失败\n");
            } else {
                self::saveLog("异步请求发送成功\n");
            }
        } catch (Exception $e) {
            self::saveLog("异步请求异常: " . $e->getMessage() . "\n");
        } catch (Error $e) {
            self::saveLog("异步请求错误: " . $e->getMessage() . "\n");
        }
        
        self::saveLog("请求结束\n");
    }

    /**
     * Socket 请求
     * @param $url
     * @return bool
     */
    public static function socket($url)
    {
        $params = parse_url($url);
        $path = $params['path'] . '?' . $params['query'];
        $host = $params['host'];
        $port = isset($params['port']) ? $params['port'] : 80;
        $scheme = '';

        if ('https' == $params['scheme']) {
            $port = isset($params['port']) ? $params['port'] : 443;
            $scheme = 'ssl://';
        }

        self::saveLog("Socket连接信息 - Host: {$host}, Port: {$port}, Path: {$path}\n");

        if (function_exists('fsockopen')) {
            $fp = @fsockopen ($scheme . $host, $port, $errno, $errstr, 30);
        } elseif (function_exists('pfsockopen')) {
            $fp = @pfsockopen ($scheme . $host, $port, $errno, $errstr, 30);
        } else {
            $fp = stream_socket_client($scheme . $host . ":$port", $errno, $errstr, 30);
        }

        if ($fp === false) {
            self::saveLog("Socket连接失败: [" . $errno . '] ' . $errstr . "\n");
            return false;
        }

        $out = "GET " . $path . " HTTP/1.1\r\n";
        $out .= "Host: $host\r\n";
        $out .= "Connection: Close\r\n\r\n";

        self::saveLog("发送HTTP请求头\n");

        if (!fwrite($fp, $out)) {
            self::saveLog("写入请求头失败\n");
            fclose($fp);
            return false;
        }

        // 读取响应头以确认请求成功
        $response = '';
        $headerReceived = false;
        while (!feof($fp) && !$headerReceived) {
            $line = fgets($fp, 1024);
            $response .= $line;
            if (trim($line) == '') {
                $headerReceived = true;
            }
        }

        fclose($fp);

        // 检查HTTP状态码
        if (preg_match('/HTTP\/1\.[01] (\d{3})/', $response, $matches)) {
            $statusCode = $matches[1];
            self::saveLog("HTTP响应状态码: {$statusCode}\n");
            if ($statusCode == '200') {
                return true;
            } else {
                self::saveLog("HTTP请求失败，状态码: {$statusCode}\n");
                return false;
            }
        } else {
            self::saveLog("无法解析HTTP响应\n");
            return false;
        }
    }

    /*
     * Curl 方式发送 HTTP 请求
     * $url 请求地址
     */
    public static function curl($url)
    {
        self::saveLog("使用Curl发送请求: {$url}\n");
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'CommentToMail Plugin');
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        if ($result === false) {
            self::saveLog("Curl请求失败: {$error}\n");
            curl_close($ch);
            return false;
        }
        
        self::saveLog("Curl请求成功，HTTP状态码: {$httpCode}\n");
        
        if ($httpCode != 200) {
            self::saveLog("HTTP请求失败，状态码: {$httpCode}\n");
            curl_close($ch);
            return false;
        }
        
        curl_close($ch);
        return true;
    }

    /**
     * 检测 适配器
     * @return string
     */
    public static function isAvailable()
    {
        function_exists('ini_get') && ini_get('allow_url_fopen') && (self::$_adapter = 'Socket');
        false == self::$_adapter && function_exists('curl_version') && (self::$_adapter = 'Curl');
        
        return self::$_adapter;
    }

    /**
     * 检测 是否可写
     * @param $file
     * @return bool
     */
    public static function isWritable($file)
    {
        if (is_dir($file)) {
            $dir = $file;
            if ($fp = @fopen("$dir/check_writable", 'w')) {
                @fclose($fp);
                @unlink("$dir/check_writable");
                $writeable = true;
            } else {
                $writeable = false;
            }
        } else {
            if ($fp = @fopen($file, 'a+')) {
                @fclose($fp);
                $writeable = true;
            } else {
                $writeable = false;
            }
        }

        return $writeable;
    }

    /**
     * 写入记录
     * @param $content
     * @return bool
     */
    public static function saveLog($content)
    {
        if (!self::$_isMailLog) {
            return false;
        }

        file_put_contents(dirname(__FILE__) . '/log/mailer_log.txt', $content, FILE_APPEND);
    }
}
