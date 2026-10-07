<?php
/**
 *
 * Modern quote, extension for phpBB to make selective quoting possible.
 *
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace ger\modernquote\event;

/**
 * @ignore
 */
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event listener.
 */
class main_listener implements EventSubscriberInterface
{
    protected $request;
    protected $config;
    protected $user;
    protected $template;
    protected $phpbb_content_visibility;
    protected $db;
    protected $bbcode_utils;
    protected $lang;
    protected $auth;
    protected $fqcookie_suffix = '_fqcookie';
    protected $fqcookie_full = '';
    protected $mqcookie = 'mqcookie';


    static public function getSubscribedEvents()
    {
        return array(
            'core.modify_posting_auth'          => 'override_post_text',
            'core.posting_modify_template_vars' => 'get_multiquote_content',
            'core.viewtopic_modify_post_row'	=> 'add_post_row_vars',
            'core.viewtopic_modify_page_title'	=> 'add_viewtopic_vars',
        );
    }

    public function __construct(
        \phpbb\request\request $request, 
        \phpbb\config\config $config,
        \phpbb\user $user,
        \phpbb\template\template $template,
        \phpbb\content_visibility $phpbb_content_visibility, 
        \phpbb\db\driver\driver_interface $db, 
        \phpbb\textformatter\s9e\utils $bbcode_utils, 
        \phpbb\language\language $lang,
        \phpbb\auth\auth $auth)
    {
        $this->request = $request;
        $this->config = $config;
        $this->user = $user;
        $this->template = $template;
        $this->phpbb_content_visibility = $phpbb_content_visibility;
        $this->db = $db;
        $this->bbcode_utils = $bbcode_utils;
        $this->lang = $lang;
        $this->auth = $auth;

        $this->fqcookie_full = $this->config['cookie_name'] . $this->fqcookie_suffix;
    }
    
    /**
     * Add post time to topic row
     */
    public function add_post_row_vars($event)
    {
        $this->lang->add_lang('common', 'ger/modernquote');
        $post_row = $event['post_row'];
        $post_row['POST_TIME'] = $event['row']['post_time'];
        $event['post_row'] = $post_row;
    }
    
    
    /**
     * Assign cookie name to template and clear anything in FQ cookie
     */
    public function add_viewtopic_vars($event)
    {
        $this->template->assign_vars(array(
            'COOKIE_NAME' => $this->config['cookie_name'],
            'COOKIE_PATH' => $this->config['cookie_path'],
        ));
        
        $this->clear_fqcookie();
    }
    
    /**
     * Override post text with quote if needed
     */
    public function override_post_text($event)
    {
        if ($event['mode'] == 'quote')
        {
            $post_text = $this->request->variable($this->fqcookie_full, '', true, \phpbb\request\request_interface::COOKIE);
            $this->clear_fqcookie();
            if (strlen($post_text) > 0) 
            {
                $post_data = $event['post_data'];
                $post_data['post_text'] = $post_text;
                $event['post_data'] = $post_data;
            }
        }
    }
    
    /**
     * Add multiquote content to the posting screen
     */
    public function get_multiquote_content($event)
    {
        // Clear the cookie
        $set_time = time() - 31536000;
        $this->user->set_cookie($this->mqcookie, ' ', $set_time);
        
        if (($event['mode'] == 'quote') && $this->config['allow_bbcode'])
        {
            $multiquote = $this->request->variable('multiquote', '');
            if (strlen($multiquote) > 0)
            {
                $posts = explode(';', $multiquote);
            }
            if (isset($posts))
            {
                $output = '';
                $post_ids = array_filter(array_map('intval', $posts), function ($pid) {
                    return $pid > 0;
                });

                // Fetch all quoted posts at once. Each post is checked against its own forum, so only
                // posts the user could read in viewtopic are quoted: f_read, forum password, and
                // topic/post visibility. Posts that fail are left out.
                $rowset = array();
                if (!empty($post_ids))
                {
                    $sql = $this->db->sql_build_query('SELECT', array(
                        'SELECT'    => 'p.*, u.username, t.topic_visibility, t.topic_poster, f.forum_password, fa.user_id AS forum_access_user_id',
                        'FROM'      => array(
                            POSTS_TABLE  => 'p',
                            TOPICS_TABLE => 't',
                            USERS_TABLE  => 'u',
                            FORUMS_TABLE => 'f',
                        ),
                        'LEFT_JOIN' => array(
                            array(
                                'FROM' => array(FORUMS_ACCESS_TABLE => 'fa'),
                                'ON'   => "fa.forum_id = f.forum_id AND fa.session_id = '" . $this->db->sql_escape($this->user->session_id) . "'",
                            ),
                        ),
                        'WHERE'     => $this->db->sql_in_set('p.post_id', array_unique($post_ids)) . '
                            AND t.topic_id = p.topic_id
                            AND u.user_id = p.poster_id
                            AND f.forum_id = p.forum_id',
                    ));
                    $result = $this->db->sql_query($sql);
                    while ($row = $this->db->sql_fetchrow($result))
                    {
                        $forum_id = (int) $row['forum_id'];
                        if ($this->auth->acl_get('f_read', $forum_id)
                            && (!$row['forum_password'] || $row['forum_access_user_id'] == $this->user->data['user_id'])
                            && $this->phpbb_content_visibility->is_visible('topic', $forum_id, $row)
                            && $this->phpbb_content_visibility->is_visible('post', $forum_id, $row))
                        {
                            $rowset[(int) $row['post_id']] = $row;
                        }
                    }
                    $this->db->sql_freeresult($result);
                }

                foreach ($post_ids as $pid)
                {
                    if (isset($rowset[$pid]))
                    {
                        $output .= $this->get_decoded_quote($rowset[$pid]);
                    }
                }
            }
            
            if (!empty($output))
            {
                $page_data = $event['page_data'];
                $page_data['MESSAGE'] = $output;
                $event['page_data'] = $page_data;
            }
        }
    }
    
    /**
     * Decode post message and markup quote BBcode
     * Borrowed from ./posting.php
     * @param array $post_data
     * @return string
     */
    private function get_decoded_quote($post_data)
    {
        $message_parser = new \parse_message();
        $post_data['username'] = ($post_data['poster_id'] == ANONYMOUS) ? trim($post_data['post_username']) : trim($post_data['username']);
        $post_data['quote_username'] = isset($post_data['username']) ? $post_data['username'] : '';
        
        
        $message_parser->message = &$post_data['post_text'];
        unset($post_data['post_text']);
        
        // Remove quotes that would become nested too deep before decoding the text
        if ($this->config['max_quote_depth'] > 0)
        {
            $tmp_bbcode_uid = $message_parser->bbcode_uid;
            $message_parser->bbcode_uid = $post_data['bbcode_uid'];
            $message_parser->remove_nested_quotes($this->config['max_quote_depth'] - 1);
            $message_parser->bbcode_uid = $tmp_bbcode_uid;
        }
        // Decode text for message display
        $message_parser->decode_message($post_data['bbcode_uid']);
        
        // Remove attachment bbcode tags from the quoted message to avoid mixing with the new post attachments if any
        $message_parser->message = preg_replace('#\[attachment=([0-9]+)\](.*?)\[\/attachment\]#uis', '\\2', $message_parser->message);
        
        $message_parser->message = $this->bbcode_utils->generate_quote(
            censor_text($message_parser->message),
            array(
                'author'  => $post_data['quote_username'],
                'post_id' => $post_data['post_id'],
                'time'    => $post_data['post_time'],
                'user_id' => $post_data['poster_id'],
            )
        );
        $message_parser->message .= "\n\n";
        
        return $message_parser->message;
    }
    
    /**
     * Clear float quote cookie, mimics ucp delete cookies in the most simple way
     */
    private function clear_fqcookie()
    {
        $set_time = time() - 31536000;
        $this->user->set_cookie($this->fqcookie_full, ' ', $set_time);
    }
}