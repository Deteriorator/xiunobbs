<?php

// hook post_func_php_start.php

// ------------> 最原生的 CURD，无关联其他数据。

// 只用传 message, message_fmt 自动生成
function post__create($arr, $gid) {
	// hook post__create_start.php
	
	// 超长内容截取
	$arr['message'] = xn_substr($arr['message'], 0, 2028000);
	
	// 格式转换
	$arr['message_fmt'] = htmlspecialchars($arr['message']);
	$arr['doctype'] == 0 && $arr['message_fmt'] = ($gid == 1 ? $arr['message'] : xn_html_safe($arr['message']));
	$arr['doctype'] == 1 && $arr['message_fmt'] = xn_txt_to_html($arr['message']);
	
	$r = db_insert('post', $arr);
	// hook post__create_end.php
	return $r;
}

function post__update($pid, $arr) {
	// hook post__update_start.php
	$r = db_update('post', array('pid'=>$pid), $arr);
	// hook post__update_end.php
	return $r;
}

function post__read($pid) {
	// hook post__read_start.php
	$post = db_find_one('post', array('pid'=>$pid));
	// hook post__read_end.php
	return $post;
}

function post__delete($pid) {
	// hook post__delete_start.php
	$r = db_delete('post', array('pid'=>$pid));
	// hook post__delete_end.php
	return $r;
}

function post__find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20) {
	// hook post__find_start.php
	$postlist = db_find('post', $cond, $orderby, $page, $pagesize, 'pid');
	// hook post__find_end.php
	return $postlist;
}

// ------------> 关联 CURD，主要是强相关的数据，比如缓存。弱相关的大量数据需要另外处理。

// 回帖
function post_create($arr, $fid, $gid) {
	global $conf, $time;
	// hook post_create_start.php
	
	$pid = post__create($arr, $gid);
	if(!$pid) return $pid;
	
	$tid = $arr['tid'];
	$uid = $arr['uid'];

	// 回帖
	if($tid > 0) {
		thread__update($tid, array('posts+'=>1, 'lastpid'=>$pid, 'lastuid'=>$uid, 'last_date'=>$time));
		$uid AND user__update($uid, array('posts+'=>1));
	
		runtime_set('posts+', 1);
		runtime_set('todayposts+', 1);
		forum__update($fid, array('todayposts+'=>1));
	}
	
	//post_list_cache_delete($tid);
	
	// 更新板块信息。
	forum_list_cache_delete();
	
	// 关联附件
	$message = $arr['message'];
	attach_assoc_post($pid);
	
	// hook post_create_end.php
	return $pid;
}

// 编辑回帖
function post_update($pid, $arr, $tid = 0) {
	// hook post_update_start.php
	global $conf, $user, $gid;
	$post = post__read($pid);
	if(empty($post)) return FALSE;
	$tid = $post['tid'];
	$uid = $post['uid'];
	$isfirst = $post['isfirst'];
	
	// 超长内容截取
	$arr['message'] = xn_substr($arr['message'], 0, 2028000);
	
	// 格式转换
	$arr['message_fmt'] = htmlspecialchars($arr['message']);
	$arr['doctype'] == 0 && $arr['message_fmt'] = ($gid == 1 ? $arr['message'] : xn_html_safe($arr['message']));
	$arr['doctype'] == 1 && $arr['message_fmt'] = xn_txt_to_html($arr['message']);
	
	// hook post_create_post__create_before.php
	
	$r = post__update($pid, $arr);
	
	attach_assoc_post($pid);
	
	// hook post_update_end.php
	return $r;
}

function post_read($pid) {
	// hook post_read_start.php
	$post = post__read($pid);
	post_format($post);
	// hook post_read_end.php
	return $post;
}

// 从缓存中读取，避免重复从数据库取数据，主要用来前端显示，可能有延迟。重要业务逻辑不要调用此函数，数据可能不准确，因为并没有清理缓存，针对 request 生命周期有效。
function post_read_cache($pid) {
	// hook post_read_cache_start.php
	static $cache = array(); // 用静态变量只能在当前 request 生命周期缓存，要跨进程，可以再加一层缓存： memcached/xcache/apc/
	if(isset($cache[$pid])) return $cache[$pid];
	$cache[$pid] = post_read($pid);
	// hook post_read_cache_end.php
	return $cache[$pid];
}

// $tid 用来清理缓存
function post_delete($pid) {
	// hook post_delete_start.php
	global $conf;
	$post = post_read_cache($pid);
	if(empty($post)) return TRUE; // 已经不存在了。
	
	$tid = $post['tid'];
	$uid = $post['uid'];
	$thread = thread_read_cache($tid);
	$fid = $thread['fid'];
	
	$r = post__delete($pid);
	if($r === FALSE) return FALSE;
	
	if(!$post['isfirst']) {
		thread__update($tid, array('posts-'=>1));
		$uid AND user__update($uid, array('posts-'=>1));
		runtime_set('posts-', 1);
	} else {
		//post_list_cache_delete($tid);
	}
	
	($post['images'] || $post['files']) AND attach_delete_by_pid($pid);
	
	// hook post_delete_end.php
	return $r;
}

// 此处有可能会超时
function post_delete_by_tid($tid) {
	// hook post_delete_by_tid_start.php
	$postlist = post_find_by_tid($tid);
	foreach($postlist as $post) {
		post_delete($post['pid']);
	}
	// hook post_delete_by_tid_end.php
	return count($postlist);
}

function post_find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20) {
	// hook post_find_start.php
	$postlist = post__find($cond, $orderby, $page, $pagesize);
	$floor = 0;
	if($postlist) foreach($postlist as &$post) {
		$post['floor'] = $floor++;
		post_format($post);
	}
	// hook post_find_end.php
	return $postlist;
}

// 此处有缓存，是否有必要？
function post_find_by_tid($tid, $page = 1, $pagesize = 50) {
	// hook post_find_by_tid_start.php
	global $conf;
	empty($pagesize) AND $pagesize = $conf['postlist_pagesize'];

	$postlist = post__find(array('tid'=>$tid), array('pid'=>1), $page, $pagesize);
	
	if($postlist) {
		$floor = ($page - 1)* $pagesize;
		foreach($postlist as &$post) {
			$post['floor'] = $floor++;
			post_format($post);
		}
	}
	// hook post_find_by_tid_end.php
	return $postlist;
}
/*
function post_list_cache_delete($tid) {
	// hook post_list_cache_delete_start.php
	global $conf;
	$r = cache_delete("postlist_$tid");
	// hook post_list_cache_delete_end.php
	return $r;
}*/

// ------------> 其他方法

function post_format(&$post) {
	// hook post_format_start.php
	global $conf;
	if(empty($post)) return;
	$post['create_date_fmt'] = humandate($post['create_date']);
	
	$user = $post['uid'] ? user_read_cache($post['uid']) : user_guest();
	$post['username'] = $user['username'];
	$post['user_avatar_url'] = $user['avatar_url'];
	$post['user'] = $user;
	!isset($post['floor']) AND  $post['floor'] = '';
	
	// 权限判断
	global $uid, $sid, $longip;
	$post['allowupdate'] = ($uid == $post['uid']);
	$post['allowdelete'] = ($uid == $post['uid']);
	
	$post['user_url'] = url("user-$post[uid]".($post['uid'] ? '' : "-$post[pid]"));
	
	if($post['files'] > 0) {
		list($attachlist, $imagelist, $filelist) = attach_find_by_pid($post['pid']);
		$post['filelist'] = $filelist;
	} else {
		$post['filelist'] = array();
	}
	
	// 内容转换:更多格式用插件实现
	
	// hook post_format_end.php
}

function post_count($cond = array()) {
	// hook post_count_start.php
	$n = db_count('post', $cond);
	// hook post_count_end.php
	return $n;
}

function post_maxid() {
	// hook post_maxid_start.php
	$n = db_maxid('post', 'pid');
	// hook post_maxid_end.php
	return $n;
}

function post_highlight_keyword($str, $k) {
	// hook post_highlight_keyword_start.php
	$r = str_ireplace($k, '<span class="red">'.$k.'</span>', $str);
	// hook post_highlight_keyword_end.php
	return $r;
}

// 公用的附件模板，采用函数，效率比 include 高。
function post_file_list_html($filelist, $include_delete = FALSE) {
	if(empty($filelist)) return '';
	$s = '<ul class="attachlist">'."\r\n";
	foreach ($filelist as $attach) {
		$s .= '<li aid="'.$attach['aid'].'">'."\r\n";
		$s .= '		<a href="'.$attach['url'].'" target="_blank">'."\r\n";
		$s .= '			<i class="icon filetype '.$attach['filetype'].'"></i>'."\r\n";
		$s .= '			'.$attach['orgfilename']."\r\n";
		$s .= '		</a>'."\r\n";
		$include_delete AND $s .= '		<a href="javascript:void(0)" class="delete m-l-1"><i class="icon-remove"></i> 删除</a>'."\r\n";
		$s .= '</li>'."\r\n";
	};
	$s .= '</ul>'."\r\n";
	return $s;
}

// hook post_func_php_end.php

?>