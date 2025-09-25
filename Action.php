<?php
/**
 * CommentToMail Plugin
 * 异步发送提醒邮件到博主或访客的邮箱
 * 
 * @copyright  Copyright (c) 2014 Byends (http://www.byends.com)
 * @license    GNU General Public License 2.0
 */
class CommentToMail_Action extends Typecho_Widget implements Widget_Interface_Do
{
    /** @var  数据操作对象 */
    private $_db;
    
    /** @var  插件根目录 */
    private $_dir;
    
    /** @var  插件配置信息 */
    private $_cfg;
    
    /** @var  系统配置信息 */
    private $_options;
    
    /** @var bool 是否记录日志 */
    private $_isMailLog = false;
    
    /** @var 当前登录用户 */
    private $_user;
    
    /** @var  邮件内容信息 */
    private  $_email;

    /**
     * 执行函数
     */
    public function process()
    {
        // 立即启用日志记录，确保能捕获所有处理过程
        $this->_isMailLog = true;
        
        try {
            $this->mailLog(false, '=== 开始处理异步请求 ===');
            $this->mailLog(false, 'PHP版本: ' . PHP_VERSION);
            $this->mailLog(false, '当前时间: ' . date('Y-m-d H:i:s'));
            $this->mailLog(false, '请求方法: ' . $_SERVER['REQUEST_METHOD']);
            $this->mailLog(false, '请求URI: ' . $_SERVER['REQUEST_URI']);
            $this->mailLog(false, '请求参数 send: ' . $this->request->send);
            
            // 初始化配置
            if (!$this->init()) {
                $this->mailLog('初始化失败');
                return false;
            }
            
            // 检查缓存文件是否存在
            $file = $this->_dir . '/cache/' . $this->request->send;
            $this->mailLog('检查缓存文件: ' . $file);
            
            if (!file_exists($file)) {
                $this->mailLog('缓存文件不存在: ' . $file);
                if (is_dir($this->_dir . '/cache/')) {
                    $cacheFiles = scandir($this->_dir . '/cache/');
                    $this->mailLog('缓存目录内容: ' . implode(', ', array_filter($cacheFiles, function($f) { return $f !== '.' && $f !== '..'; })));
                } else {
                    $this->mailLog('缓存目录不存在');
                }
                $this->mailLog('=== 异步请求处理结束（缓存文件不存在）===');
                return false;
            }
            
            $this->mailLog('缓存文件存在，开始读取');
            
            // 读取缓存文件内容
            $content = file_get_contents($file);
            if ($content === false) {
                $this->mailLog('无法读取缓存文件: ' . $file);
                $this->mailLog('文件权限: ' . substr(sprintf('%o', fileperms($file)), -4));
                return false;
            }
            
            $this->mailLog('缓存文件读取成功，大小: ' . strlen($content) . ' bytes');
            
            // 反序列化数据，PHP8兼容性处理
            $this->_email = @unserialize($content);
            if ($this->_email === false || !is_object($this->_email)) {
                $this->mailLog('缓存文件反序列化失败: ' . $file);
                $this->mailLog('文件内容预览: ' . substr($content, 0, 200));
                $this->mailLog('序列化错误: ' . (error_get_last()['message'] ?? '未知错误'));
                return false;
            }
            
            $this->mailLog('数据反序列化成功');
            
            // 验证必要的配置信息
            if (!$this->_cfg || !isset($this->_cfg->user) || empty($this->_cfg->user)) {
                $this->mailLog('插件配置信息不完整，缺少发件人邮箱');
                return false;
            }
            
            $this->mailLog('配置验证通过');
            
            // 确保邮件对象属性存在，PHP8兼容性处理
            $requiredProps = ['status', 'parent', 'coid', 'authorId', 'ownerId'];
            foreach ($requiredProps as $prop) {
                if (!property_exists($this->_email, $prop)) {
                    $this->mailLog("邮件对象缺少必要属性: {$prop}");
                    return false;
                }
            }
            
            // 设置发件人信息
            $this->_email->from = $this->_cfg->user;
            $this->_email->fromName = $this->_cfg->fromName ? $this->_cfg->fromName : $this->_options->title;

            // 设置邮件发送标志，兼容旧版本配置
            $this->_email->toMe = $this->shouldSendToOwner();
            $this->_email->toGuest = $this->shouldSendToGuest();

            $this->mailLog('邮件对象配置完成');
            $this->mailLog('评论状态: ' . $this->_email->status);
            $this->mailLog('toMe: ' . ($this->_email->toMe ? '是' : '否'));
            $this->mailLog('toGuest: ' . ($this->_email->toGuest ? '是' : '否'));
            $this->mailLog('parent: ' . $this->_email->parent);

            /** 如果设置了邮件屏蔽，检查是否屏蔽 */
            if (isset($this->_email->banMail) && $this->_email->banMail) {
                $this->mailLog('邮件被屏蔽');
                $this->ban();
            }

            /** 发送邮件给博主 */
            if ($this->_email->toMe && 'approved' == $this->_email->status) {
                $this->mailLog('准备发送邮件给博主');
                
                // 设置博主收件人邮箱，与 processOld() 逻辑一致
                if (empty($this->_cfg->mail)) {
                    // 获取博主用户信息
                    $user = null;
                    try {
                        Typecho_Widget::widget('Widget_Users_Author@temp' . $this->_email->cid, array('uid' => $this->_email->ownerId))->to($user);
                        $this->_email->to = $user->mail;
                    } catch (Exception $e) {
                        $this->mailLog('获取博主用户信息失败: ' . $e->getMessage());
                        $this->_email->to = '';
                    }
                } else {
                    $this->_email->to = $this->_cfg->mail;
                }

                // 如果博主邮箱为空，尝试使用当前登录用户邮箱作为回退
                if (empty($this->_email->to) && isset($this->_user) && !empty($this->_user->mail)) {
                    $this->_email->to = $this->_user->mail;
                    $this->mailLog('博主邮箱缺失，使用当前用户邮箱作为收件人: ' . $this->_email->to);
                }

                // 若仍为空，尝试使用发件人邮箱作为最终回退
                if (empty($this->_email->to) && !empty($this->_cfg->user)) {
                    $this->_email->to = $this->_cfg->user;
                    $this->mailLog('博主邮箱仍为空，使用发件人邮箱作为收件人: ' . $this->_email->to);
                }

                // 最终判断是否可发送
                if (empty($this->_email->to)) {
                    $this->mailLog('收件人邮箱未设置（博主），已跳过发送');
                    $result = '收件人邮箱未设置';
                } else {
                    $this->mailLog('博主收件人邮箱: ' . $this->_email->to);
                    $result = $this->authorMail()->sendMail();
                }
                
                $this->mailLog('博主邮件发送结果: ' . ($result === true ? '成功' : $result));
            }

            /** 发送邮件给访客 */
            if ($this->_email->toGuest && 'approved' == $this->_email->status && $this->_email->parent) {
                $this->mailLog('准备发送邮件给访客');
                
                // 设置联系我邮箱
                if (empty($this->_email->contactme)) {
                    if (!isset($user) || !$user) {
                        try {
                            Typecho_Widget::widget('Widget_Users_Author@temp' . $this->_email->cid, array('uid' => $this->_email->ownerId))->to($user);
                        } catch (Exception $e) {
                            $this->mailLog('获取博主用户信息失败: ' . $e->getMessage());
                            $user = null;
                        }
                    }
                    $this->_email->contactme = $user ? $user->mail : '';
                } else {
                    $this->_email->contactme = $this->_cfg->contactme;
                }

                // 获取原评论者信息
                $original = $this->_db->fetchRow($this->_db->select('author', 'mail', 'text')
                                                           ->from('table.comments')
                                                           ->where('coid = ?', $this->_email->parent));

                if (in_array('to_me', $this->_cfg->other) 
                    || $this->_email->mail != $original['mail']) {
                    // 若原评论者邮箱为空，则跳过访客邮件发送
                    if (empty($original['mail'])) {
                        $this->mailLog('原评论者邮箱为空，已跳过访客邮件发送');
                        $result = '原评论者邮箱为空';
                    } else {
                        $this->_email->to             = $original['mail'];
                        $this->_email->originalText   = $original['text'];
                        $this->_email->originalAuthor = $original['author'];
                        $this->mailLog('访客收件人邮箱: ' . $this->_email->to);
                        $result = $this->guestMail()->sendMail();
                    }
                } else {
                    $this->mailLog('访客邮件发送被跳过（相同邮箱或配置限制）');
                    $result = '跳过发送';
                }
                
                $this->mailLog('访客邮件发送结果: ' . ($result === true ? '成功' : $result));
            }

            $this->mailLog('邮件处理完成，删除缓存文件');
            
            /** 删除缓存文件 */
            $deleteResult = @unlink($file);
            $this->mailLog('缓存文件删除结果: ' . ($deleteResult ? '成功' : '失败'));
            
            $this->mailLog('=== 异步请求处理完成 ===');
            
        } catch (Exception $e) {
            $this->mailLog('处理异步请求时发生异常: ' . $e->getMessage());
            $this->mailLog('异常堆栈: ' . $e->getTraceAsString());
            return false;
        } catch (Error $e) {
            $this->mailLog('处理异步请求时发生致命错误: ' . $e->getMessage());
            $this->mailLog('错误堆栈: ' . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * 判断是否应该发送邮件给博主
     * @return bool
     */
    private function shouldSendToOwner()
    {
        if (!isset($this->_cfg->other) || !is_array($this->_cfg->other)) {
            return false;
        }
        
        // 检查是否启用了发送给博主的功能
        if (!in_array('to_owner', $this->_cfg->other)) {
            return false;
        }
        
        // 检查评论状态是否符合发送条件
        if (!isset($this->_cfg->status) || !in_array($this->_email->status, $this->_cfg->status)) {
            return false;
        }
        
        // 如果是博主自己的评论，检查是否允许发送给自己
        if ($this->_email->ownerId == $this->_email->authorId) {
            return in_array('to_me', $this->_cfg->other);
        }
        
        // 只有原创评论（非回复）才发送给博主
        return $this->_email->parent == 0;
    }

    /**
     * 判断是否应该发送邮件给访客
     * @return bool
     */
    private function shouldSendToGuest()
    {
        if (!isset($this->_cfg->other) || !is_array($this->_cfg->other)) {
            return false;
        }
        
        // 检查是否启用了发送给访客的功能
        if (!in_array('to_guest', $this->_cfg->other)) {
            return false;
        }
        
        // 只有回复评论才发送给访客
        if (!$this->_email->parent || $this->_email->parent == 0) {
            return false;
        }
        
        // 评论必须是已批准状态
        return $this->_email->status == 'approved';
    }

    /**
     * 读取缓存文件内容
     */
    public function processOld($fileName)
    {
        $this->init();
        //获取评论内容
        $file = $this->_dir . '/cache/' . $fileName;
        if (file_exists($file)) {
            $fileContent = file_get_contents($file);
            if ($fileContent !== false) {
                $this->_email = unserialize($fileContent);
                @unlink($file);
                
                // 检查反序列化是否成功
                if ($this->_email === false || !is_object($this->_email)) {
                    $this->mailLog(false, "缓存文件反序列化失败: {$fileName}\r\n");
                    $this->widget('Widget_Archive@404', 'type=404')->render();
                    exit;
                }

                if (!$this->_user->simpleLogin($this->_email->ownerId)) {
                    $this->widget('Widget_Archive@404', 'type=404')->render();
                    exit;
                }
            } else {
                $this->mailLog(false, "无法读取缓存文件: {$fileName}\r\n");
                $this->widget('Widget_Archive@404', 'type=404')->render();
                exit;
            }
        } else {
            $this->mailLog(false, "缓存文件不存在: {$fileName}\r\n");
            $this->widget('Widget_Archive@404', 'type=404')->render();
            exit;
        }
        
        // 验证必要的配置是否存在
        if (!$this->_cfg || !isset($this->_cfg->user) || empty($this->_cfg->user)) {
            $this->mailLog(false, "邮件配置不完整，缺少发件人邮箱\r\n");
            return;
        }
        
        //如果本次评论设置了拒收邮件，把coid加入拒收列表
        if (isset($this->_email->banMail) && $this->_email->banMail) {
            $this->ban($this->_email->coid, true);
        }

        //发件人邮箱
        $this->_email->from = $this->_cfg->user;

        //发件人名称
        $this->_email->fromName = $this->_cfg->fromName ? $this->_cfg->fromName : $this->_email->siteTitle;

        //向blogger发邮件的标题格式
        $this->_email->titleForOwner = $this->_cfg->titleForOwner;

        //向访客发邮件的标题格式
        $this->_email->titleForGuest = $this->_cfg->titleForGuest;
        
        //验证博主是否接收自己的邮件
        $toMe = (in_array('to_me', $this->_cfg->other) && $this->_email->ownerId == $this->_email->authorId) ? true : false;

        //向blogger发信
        if (in_array($this->_email->status, $this->_cfg->status) && in_array('to_owner', $this->_cfg->other)
            && ( $toMe || $this->_email->ownerId != $this->_email->authorId) && 0 == $this->_email->parent ) {
            if (empty($this->_cfg->mail)) {
                Typecho_Widget::widget('Widget_Users_Author@temp' . $this->_email->cid, array('uid' => $this->_email->ownerId))->to($user);
            	$this->_email->to = $user->mail;
            } else {
                $this->_email->to = $this->_cfg->mail;
            }

            // 如果博主邮箱为空，尝试使用当前登录用户邮箱作为回退
            if (empty($this->_email->to) && isset($this->_user) && !empty($this->_user->mail)) {
                $this->_email->to = $this->_user->mail;
                $this->mailLog(false, "博主邮箱缺失，使用当前用户邮箱作为收件人: " . $this->_email->to . "\r\n");
            }

            // 若仍为空，尝试使用发件人邮箱作为最终回退
            if (empty($this->_email->to) && !empty($this->_cfg->user)) {
                $this->_email->to = $this->_cfg->user;
                $this->mailLog(false, "博主邮箱仍为空，使用发件人邮箱作为收件人: " . $this->_email->to . "\r\n");
            }

            // 最终判断是否可发送
            if (empty($this->_email->to)) {
                $this->mailLog(false, "收件人邮箱未设置（博主），已跳过发送\r\n");
            } else {
                $this->mailLog(false, "准备发送邮件给博主: " . $this->_email->to . "\r\n");
                $this->authorMail()->sendMail();
            }
        }

        //向访客发信
        if (0 != $this->_email->parent 
            && 'approved' == $this->_email->status 
            && in_array('to_guest', $this->_cfg->other)
            && !$this->ban($this->_email->parent)) {
            //如果联系我的邮件地址为空，则使用文章作者的邮件地址
            if (empty($this->_email->contactme)) {
                if (!isset($user) || !$user) {
                    Typecho_Widget::widget('Widget_Users_Author@temp' . $this->_email->cid, array('uid' => $this->_email->ownerId))->to($user);
                }
                $this->_email->contactme = $user->mail;
            } else {
                $this->_email->contactme = $this->_cfg->contactme;
            }

            $original = $this->_db->fetchRow($this->_db->select('author', 'mail', 'text')
                                                       ->from('table.comments')
                                                       ->where('coid = ?', $this->_email->parent));

            if (in_array('to_me', $this->_cfg->other) 
                || $this->_email->mail != $original['mail']) {
                // 若原评论者邮箱为空，则跳过访客邮件发送
                if (empty($original['mail'])) {
                    $this->mailLog(false, "原评论者邮箱为空，已跳过访客邮件发送\r\n");
                } else {
                    $this->_email->to             = $original['mail'];
                    $this->_email->originalText   = $original['text'];
                    $this->_email->originalAuthor = $original['author'];
                    $this->mailLog(false, "准备发送邮件给访客: " . $this->_email->to . "\r\n");
                    $this->guestMail()->sendMail();
                }
            }
        }

        $date = new Typecho_Date(Typecho_Date::gmtTime());
        $time = $date->format('Y-m-d H:i:s');
        $this->mailLog(false, $time . " 邮件发送完毕!\r\n");
    }

    /**
     * 作者邮件信息
     * @return $this
     */
    public function authorMail()
    {
        $this->_email->toName = $this->_email->siteTitle;
        $date = new Typecho_Date($this->_email->created);
        $time = $date->format('Y-m-d H:i:s');
        $status = array(
            "approved" => '通过',
            "waiting"  => '待审',
            "spam"     => '垃圾'
        );
        $search  = array(
            '{siteTitle}',
            '{title}',
            '{author}',
            '{ip}',
            '{mail}',
            '{permalink}',
            '{manage}',
            '{text}',
            '{time}',
            '{status}'
        );
        $replace = array(
            $this->_email->siteTitle,
            $this->_email->title,
            $this->_email->author,
            $this->_email->ip,
            $this->_email->mail,
            $this->_email->permalink,
            $this->_email->manage,
            $this->_email->text,
            $time,
            $status[$this->_email->status]
        );

        $this->_email->msgHtml = str_replace($search, $replace, $this->getTemplate('owner'));
        $this->_email->subject = str_replace($search, $replace, $this->_email->titleForOwner);
        $this->_email->altBody = "作者：".$this->_email->author."\r\n链接：".$this->_email->permalink."\r\n评论：\r\n".$this->_email->text;

        return $this;
    }

    /**
     * 访问邮件信息
     * @return $this
     */
    public function guestMail()
    {
        $this->_email->toName = $this->_email->originalAuthor ? $this->_email->originalAuthor : $this->_email->siteTitle;
        $date    = new Typecho_Date($this->_email->created);
        $time    = $date->format('Y-m-d H:i:s');
        $search  = array(
            '{siteTitle}',
            '{title}',
            '{author_p}',
            '{author}',
            '{permalink}',
            '{text}',
            '{contactme}',
            '{text_p}',
            '{time}'
        );
        $replace = array(
            $this->_email->siteTitle,
            $this->_email->title,
            $this->_email->originalAuthor,
            $this->_email->author,
            $this->_email->permalink,
            $this->_email->text,
            $this->_email->contactme,
            $this->_email->originalText,
            $time
        );

        $this->_email->msgHtml = str_replace($search, $replace, $this->getTemplate('guest'));
        $this->_email->subject = str_replace($search, $replace, $this->_email->titleForGuest);
        $this->_email->altBody = "作者：".$this->_email->author."\r\n链接：".$this->_email->permalink."\r\n评论：\r\n".$this->_email->text;

        return $this;
    }

    /*
     * 发送邮件
     */
    public function sendMail()
    {
        /** 载入邮件组件 */
        require_once $this->_dir . '/lib/class.phpmailer.php';
        
        // 检查必要的邮件信息是否存在
        if (!isset($this->_email->from) || empty($this->_email->from)) {
            $this->mailLog(false, "发件人邮箱未设置\r\n");
            return "发件人邮箱未设置";
        }
        
        if (!isset($this->_email->to) || empty($this->_email->to)) {
            $this->mailLog(false, "收件人邮箱未设置\r\n");
            return "收件人邮箱未设置";
        }
        
        $mailer = new PHPMailer();
        $mailer->CharSet = 'UTF-8';
        $mailer->Encoding = 'base64';
        
        // 启用调试模式以获取更详细的错误信息
        $mailer->SMTPDebug = 2;
        $mailer->Debugoutput = function($str, $level) {
            $this->mailLog(false, "SMTP Debug: " . $str . "\r\n");
        };

        //选择发信模式
        switch ($this->_cfg->mode)
        {
            case 'mail':
                break;
            case 'sendmail':
                $mailer->IsSendmail();
                break;
            case 'smtp':
                $mailer->IsSMTP();

                if (in_array('validate', $this->_cfg->validate)) {
                    $mailer->SMTPAuth = true;
                }

                if (in_array('ssl', $this->_cfg->validate)) {
                    $mailer->SMTPSecure = "ssl";
                }

                $mailer->Host     = $this->_cfg->host;
                $mailer->Port     = $this->_cfg->port;
                $mailer->Username = $this->_cfg->user;
                $mailer->Password = $this->_cfg->pass;
                
                // 增加连接超时时间
                $mailer->Timeout = 30;
                
                // 记录SMTP配置信息用于调试
                $this->mailLog(false, "SMTP配置 - Host: " . $this->_cfg->host . ", Port: " . $this->_cfg->port . ", User: " . $this->_cfg->user . "\r\n");

                break;
        }

        $mailer->SetFrom($this->_email->from, $this->_email->fromName);
        $mailer->AddReplyTo($this->_email->to, $this->_email->toName);
        $mailer->Subject = $this->_email->subject;
        $mailer->AltBody = $this->_email->altBody;
        $mailer->MsgHTML($this->_email->msgHtml);
        $mailer->AddAddress($this->_email->to, $this->_email->toName);

        if ($result = $mailer->Send()) {
            $this->mailLog(true, "邮件发送成功\r\n");
        } else {
            $this->mailLog(false, "邮件发送失败: " . $mailer->ErrorInfo . "\r\n");
            $result = $mailer->ErrorInfo;
        }
        
        $mailer->ClearAddresses();
        $mailer->ClearReplyTos();

        return $result;
    }

    /*
     * 记录邮件发送日志和错误信息
     * 注意：为了兼容历史调用，若第一个参数不是布尔值，则将其视为日志内容，避免误判为成功。
     */
    public function mailLog($type = true, $content = null)
    {
        if (!$this->_isMailLog) {
            return false;
        }

        $fileName = $this->_dir . '/log/mailer_log.txt';
        
        // 确保日志目录存在
        $logDir = dirname($fileName);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        // 兼容旧调用：如果$type不是布尔值，当作$content
        if (!is_bool($type)) {
            $content = $type;
            $type = false;
        }
        
        // 构造日志内容
        if ($type) {
            if (isset($this->_email->to) && !empty($this->_email->to)) {
                $guest = explode('@', $this->_email->to);
                $guest = substr($this->_email->to, 0, 1) . '***' . (isset($guest[1]) ? $guest[1] : '');
                $content = $content ? $content : "向 " . $guest . " 发送邮件成功！\r\n";
            } else {
                $content = $content ? $content : "邮件发送成功！\r\n";
            }
        } else {
            // 普通内容日志，确保有换行
            $content = ($content !== null ? $content : '') . "\r\n";
        }

        // 添加时间戳
        $date = date('Y-m-d H:i:s');
        $logContent = "[{$date}] " . $content;
        
        @file_put_contents($fileName, $logContent, FILE_APPEND | LOCK_EX);
    }

    /*
     * 获取邮件正文模板
     * $author owner为博主 guest为访客
     */
    public function getTemplate($template = 'owner')
    {
        $template .= '.html';
        $filename = $this->_dir . '/' . $template;

        if (!file_exists($filename)) {
           throw new Typecho_Widget_Exception('模板文件' . $template . '不存在', 404);
        }

        return file_get_contents($this->_dir . '/' . $template);
    }

    /*
     * 验证原评论者是否接收评论
     */
    public function ban($parent, $isWrite = false)
    {
        if ($parent) {
            $index    = ceil($parent / 500);
            $filename = $this->_dir . '/log/ban_' . $index . '.list';

            if (!file_exists($filename)) {
                $list = array();
                file_put_contents($filename, serialize($list));
            } else {
                $list = unserialize(file_get_contents($filename));
            }

            //写入记录
            if ($isWrite) {
                $list[$parent] = 1;
                file_put_contents($filename, serialize($list));

                return true;
            } else if (isset($list[$parent]) && $list[$parent]) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 邮件发送测试
     */
    public function testMail()
    {
        if (Typecho_Widget::widget('CommentToMail_Console')->testMailForm()->validate()) {
            $this->response->goBack();
        }

        $this->init();
        $this->_isMailLog = true;
        $email = $this->request->from('toName', 'to', 'title', 'content');
        
        // 初始化 _email 对象，PHP 8 要求对象在使用前必须初始化
        $this->_email = new stdClass();
        
        $this->_email->from = $this->_cfg->user;
        $this->_email->fromName = $this->_cfg->fromName ? $this->_cfg->fromName : $this->_options->title;
        $this->_email->to = $email['to'] ? $email['to'] : $this->_user->mail;
        $this->_email->toName = $email['toName'] ? $email['toName'] : $this->_user->screenName;
        $this->_email->subject = $email['title'];
        $this->_email->altBody = $email['content'];
        $this->_email->msgHtml = $email['content'];

        $result = $this->sendMail();

        /** 提示信息 */
        $this->widget('Widget_Notice')->set(true === $result ? _t('邮件发送成功') : _t('邮件发送失败：' . $result),
            true === $result ? 'success' : 'notice');

        /** 转向原页 */
        $this->response->goBack();
    }

    /**
     * 编辑模板文件
     * @param $file
     * @throws Typecho_Widget_Exception
     */
    public function editTheme($file)
    {
        $this->init();
        $path = $this->_dir . '/' . $file;

        if (file_exists($path) && is_writeable($path)) {
            $handle = fopen($path, 'wb');
            if ($handle && fwrite($handle, $this->request->content)) {
                fclose($handle);
                $this->widget('Widget_Notice')->set(_t("文件 %s 的更改已经保存", $file), 'success');
            } else {
                $this->widget('Widget_Notice')->set(_t("文件 %s 无法被写入", $file), 'error');
            }
            $this->response->goBack();
        } else {
            throw new Typecho_Widget_Exception(_t('您编辑的模板文件不存在'));
        }
    }

    /**
     * 初始化
     */
    public function init()
    {
        /** 获取插件配置 */
        $this->_cfg = Helper::options()->plugin('CommentToMail');
        
        // 检查插件配置是否存在
        if (!$this->_cfg) {
            $this->mailLog('插件配置不存在，无法初始化');
            return false;
        }
        
        /** 检查是否开启邮件记录 */
        $this->_isMailLog = (isset($this->_cfg->other) && is_array($this->_cfg->other) && in_array('to_log', $this->_cfg->other));
        
        /** 获取系统配置 */
        $this->_options = Helper::options();
        
        /** 获取数据库 */
        $this->_db = Typecho_Db::get();
        
        /** 获取插件目录 */
        $this->_dir = dirname(__FILE__);
        
        /** 获取当前用户 */
        $this->_user = Typecho_Widget::widget('Widget_User');
        
        $this->mailLog('邮件发送动作开始初始化');
        
        return true;
    }

    /**
     * action 入口
     *
     * @access public
     * @return void
     */
    public function action()
    {
        $this->on($this->request->is('do=testMail'))->testMail();
        $this->on($this->request->is('do=editTheme'))->editTheme($this->request->edit);
        $this->on($this->request->is('send'))->process($this->request->send);
    }
}