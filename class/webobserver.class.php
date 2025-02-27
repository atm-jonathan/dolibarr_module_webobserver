<?php

require_once __DIR__ . '/httpHeaders.trait.php';

class WebObserver {

	use webObserver\HttpHeaders;

	/**
	 * @var string 		Error string
	 * @see             $errors
	 */
	public $error;

	/**
	 * @var string[]	Array of error strings
	 */
	public $errors = array();



	public static function getInstanceData(){

		global $conf, $dolibarr_main_db_host, $dolibarr_main_db_name, $dolibarr_main_db_user, $dolibarr_main_db_type;

		$instance = new stdClass;

		$instance->apiname = 'serverobserver';
		$instance->apiversion = '1.0';

		// Dolibarr main informations
		$instance->dolibarr = new stdClass;
		$instance->dolibarr->version = DOL_VERSION;
		$instance->dolibarr->version1 = $conf->global->MAIN_VERSION_LAST_INSTALL;
		$instance->dolibarr->theme = $conf->theme;

		$instance->dolibarr->path=new stdClass;
		$instance->dolibarr->path->http = dol_buildpath('/',2);

		$instance->dolibarr->data = new stdClass;
		$instance->dolibarr->data->path = DOL_DATA_ROOT;
		$instance->dolibarr->data->size = self::getDirSize($instance->dolibarr->data->path);

		$instance->dolibarr->htdocs=new stdClass;
		$instance->dolibarr->htdocs->path = DOL_DOCUMENT_ROOT;
		$instance->dolibarr->htdocs->size = self::getDirSize($instance->dolibarr->htdocs->path);

		$instance->dolibarr->repertoire_client=new stdClass;
		$instance->dolibarr->repertoire_client->path = dirname(DOL_DOCUMENT_ROOT, 1);
		$instance->dolibarr->repertoire_client->size = self::getDirSize($instance->dolibarr->repertoire_client->path);

		// Informations about Dolibarr database
		$instance->db=new stdClass;
		$instance->db->host = $dolibarr_main_db_host;
		$instance->db->name = $dolibarr_main_db_name;
		$instance->db->user = $dolibarr_main_db_user;
		$instance->db->type = $dolibarr_main_db_type;

		// Informations about users in Dolibarr
		$instance->user=new stdClass;
		$instance->user->all = self::nb_user();
		$instance->user->active = self::nb_user(true);
		$instance->user->date_last_login = self::last_login() ;

		// Security informations
		$instance->security=new stdClass;
		$instance->security->database_pwd_encrypted = $conf->global->DATABASE_PWD_ENCRYPTED;
		$instance->security->main_features_level = $conf->global->MAIN_FEATURES_LEVEL;
		$instance->security->install_lock = file_exists(DOL_DATA_ROOT . '/install.lock');

		// Informations about module activated on the instance
		$instance->module = new stdClass;

		// fix une fonction de _module_active n'existe pas avant la 4.0
		//if (version_compare(DOL_VERSION, '4.0.0') > 0)
		$instance->module = self::module_active();

		return $instance;
	}

	/**
	 * renvoi le json des données de l'instance
	 * @return false|string
	 */
	public static function getInstanceJson(){
		return  json_encode(self::getInstanceData());
	}


	/**
	 * Check security parameters
	 * Check hash and time parameters
	 */
	public static function securityCheck() {
		global $conf;

		if(empty($conf->global->WEBOBSERVER_TOKEN)){
			return exit('Invalid token configuration');
		}

		$token = $conf->global->WEBOBSERVER_TOKEN;

		// Vérification paramètres
		if(!isset($_GET['hash'])) exit('Missing parameter');
		if(!isset($_GET['time'])) exit('Missing parameter');

		// Vérification token
		$hashToCheck = $_GET['hash'];
		$tokenTime = $_GET['time'];
		$now = time();
		$hash = md5($token . $tokenTime);
		if($hash != $hashToCheck) exit('Invalid hash');
		if($tokenTime < $now - 180) exit('Invalid hash');
		if($tokenTime > $now + 180) exit('Invalid hash');
	}


	/**
	 * Get size of a directory on the server, in bytes
	 * @param $dir    Absolute path of the directory to scan
	 * @param bool $useShellExec
	 * @param int $useCache default  43200 (12H) max 86400 (1day)
	 * @param bool $createCacheFile use true to generate cache file
	 * @return int    Size of the diectory in bytes or -1 if $dir is not a directory
	 */
	public static function getDirSize($dir, $useShellExec = false, int $useCache = 43200, $createCacheFile = false) {
		if(is_dir($dir)) {

			$getCacheFilePath = function($targetDir){
				$cacheFileName = 'getDirSize_'.md5($targetDir).'.cache';
				return self::getTmpFoldeerPath().'/'.$cacheFileName;
			};

			$cacheFilePath = $getCacheFilePath($dir);

			$useCache = min($useCache, 86400);

			if($useCache && file_exists($cacheFilePath) && is_file($cacheFilePath) && filemtime($cacheFilePath) > (time()-$useCache) ){
				$cacheSize = file_get_contents($cacheFilePath);
				if($cacheSize !== false){
					return intval($cacheSize);
				}
			}

			if($useShellExec){
				$cmd = 'du -sb ' . $dir;
				$res = shell_exec($cmd); // in bytes
				return (int)$res;
			}
			else{
				$size = 0;

				$iterator = new RecursiveDirectoryIterator($dir);
				$all_files  = new RecursiveIteratorIterator($iterator);

				foreach($all_files as $file){
					$size+=$file->getSize(); // in bytes
				}

				if ($createCacheFile && is_dir(self::getTmpFoldeerPath())) {
					file_put_contents($cacheFilePath, $size);
				}

				return $size;
			}
		}

		return -1;
	}



	public static function getTmpFoldeerPath(){
		return DOL_DATA_ROOT . '/webobserver/temp';
	}


	/**
	 * Get informations about disk space
	 * @param $dir		Directory to scan
	 * @return stdClass	Data about total space, used, left and percentages
	 */
	public static function getSystemSize($dir=__DIR__) {
		$res = new stdClass();
		$res->bytes_total = disk_total_space($dir);
		$res->bytes_left = disk_free_space($dir);
		$res->bytes_used = $res->bytes_total - $res->bytes_left;
		$res->percent_used = round($res->bytes_used * 100 / $res->bytes_total);
		$res->percent_left = 100 - $res->percent_used;

		return $res;
	}


	/**
	 * Specific functions to get informations about Dolibarr (Modules, Users, ...)
	*/
	public static function module_active() {
		include_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';

		global $db, $conf;
		$modNameLoaded = array();
		$modulesdir = dolGetModulesDirs();

		foreach ($modulesdir as $dir)
		{
			$handle = @opendir($dir);
			if (is_resource($handle))
			{
				while (($file = readdir($handle)) !== false)
				{
					if (is_readable($dir.$file) && substr($file, 0, 3) == 'mod' && substr($file, dol_strlen($file) - 10) == '.class.php')
					{
						$modName = substr($file, 0, dol_strlen($file) - 10);

						if ($modName)
						{
							try
							{
								$res = include_once $dir.$file; // A class already exists in a different file will send a non catchable fatal error.
								if (class_exists($modName))
								{
									try {
										$objMod = new $modName($db);

										$pubname = is_callable(array( $objMod, 'getPublisher')) ? $objMod->getPublisher() : $objMod->editor_name;
										$puburl = is_callable(array( $objMod, 'getPublisherUrl')) ? $objMod->getPublisherUrl() : $objMod->editor_url;

										$modNameLoaded[$modName] = new stdClass();
										$modNameLoaded[$modName]->dir = $dir;
										$modNameLoaded[$modName]->name = $objMod->name;
										$modNameLoaded[$modName]->numero = $objMod->numero;
										$modNameLoaded[$modName]->version = $objMod->version;
										$modNameLoaded[$modName]->editor_name = $objMod->editor_name;
										$modNameLoaded[$modName]->source = $objMod->isCoreOrExternalModule();
										$modNameLoaded[$modName]->gitinfos = self::getModuleGitInfos($dir);
										$modNameLoaded[$modName]->editor_name = dol_escape_htmltag($pubname);
										$modNameLoaded[$modName]->editor_url = dol_escape_htmltag($puburl);
										$modNameLoaded[$modName]->active = !empty($conf->global->{$objMod->const_name});
									}
									catch (Exception $e)
									{
										dol_syslog("Failed to load ".$dir.$file." ".$e->getMessage(), LOG_ERR);
									}
								}
								else
								{
									print "Warning bad descriptor file : ".$dir.$file." (Class ".$modName." not found into file)<br>";
								}
							}
							catch (Exception $e)
							{
								dol_syslog("Failed to load ".$dir.$file." ".$e->getMessage(), LOG_ERR);
							}
						}
					}
				}
				closedir($handle);
			}
			else
			{
				dol_syslog("htdocs/admin/modules.php: Failed to open directory ".$dir.". See permission and open_basedir option.", LOG_WARNING);
			}
		}

		return $modNameLoaded;
	}

	/**
	 * @param $dir
	 * @return stdClass
	 */
	public static function getModuleGitInfos($dir) {
		global $doneDir, $conf;
		if(isset($doneDir[$dir])) return $doneDir[$dir];

		require_once __DIR__ . '/phpGit.class.php';

		// Use PhpGit to speed
		$phpGit = new \webObserver\PhpGit($dir);
		if(!$phpGit->isGitNanaged){
			return false;
		}


		$doneDir[$dir] = new stdClass();
		$doneDir[$dir]->status = '';
		$doneDir[$dir]->branch = $phpGit->branchName;

		if(!empty($conf->global->WEBOBSERVER_USE_GIT_AND_SHELL_CMD)){
			$doneDir[$dir]->status = $phpGit->status();
		}

		return $doneDir[$dir];
	}

	/**
	 * @return mixed
	 */
	public static function last_login() {
		global $db;

		$sql = "SELECT MAX(datelastlogin) as datelastlogin FROM ".MAIN_DB_PREFIX."user WHERE 1 ";
		$sql.=" AND statut=1 AND rowid>1"; // pas l'admin

		$res = $db->query($sql);

		$obj = $db->fetch_object($res);

		return $obj->datelastlogin;
	}

	public static function nb_user($just_actif = false) {
		global $db;

		$sql = "SELECT count(*) as nb FROM ".MAIN_DB_PREFIX."user WHERE 1 ";

		if($just_actif) {
			$sql.=" AND statut=1 ";
		}

		$res = $db->query($sql);

		$obj = $db->fetch_object($res);

		return (int)$obj->nb;
	}


	/**
	 * @param int $value Durée d'expiration (en secondes) pour les flots basés sur les sockets.
	 */
	public function setSocketTimeOut($value = 0){
		if($value>0){
			$this->default_socket_timeout = intval($value);
		}

		ini_set('default_socket_timeout', $this->default_socket_timeout);
	}


	public function callWebHost($useCache = true){
		global $conf, $langs;

		// Use cache
		if($useCache && !empty($this->data)){
			return $this->data;
		}


		$instanceId = false;
		$instanceRef = false;

		$url = $this->getWebHostTargetUrl();

		if(!$url){
			$this->setError('Configuration WebHost target URL not set');
			return false;
		}

		if(!empty($conf->global->WEBOBSERVER_INSTANCE_ID)){
			$instanceId = $conf->global->WEBOBSERVER_INSTANCE_ID;
		}

		if(!empty($conf->global->WEBOBSERVER_INSTANCE_REF)){
			$instanceRef = $conf->global->WEBOBSERVER_INSTANCE_REF;
		}

		if(empty($instanceId) && empty($instanceRef)){
			$this->setError('Configuration ID OR REF not set');
			return false;
		}


		if(empty($conf->global->WEBOBSERVER_TOKEN)){
			$this->setError('MissingTokenAPI');
			return false;
		}


		$instanceData = $this->getInstanceData();
		if (!is_object($instanceData) || empty($instanceData)) {
			$this->setError($langs->transnoentities('EmptyInstanceData'));
			return false;
		}


		$time = time();
		$hash = md5($conf->global->WEBOBSERVER_TOKEN . $time);

		$url.= '?action=set-info-instance-dolibarr';
		$url.= '&hash='.$hash.'&time='.$time;
		$url.= '&api_observer_type=webobserver_v1';


		if(!empty($instanceId)){
			$url.= '&id='.intval($instanceId);
		}

		if(!empty($instanceRef)){
			$url.= '&ref='.urlencode($instanceRef);
		}

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($instanceData, JSON_FORCE_OBJECT));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		if(!$webHostResponse = curl_exec($ch)){
			$this->setError(curl_error($ch));
			return false;
		}
		curl_close($ch);

		return $webHostResponse;
	}



	/**
	 * Permet gérer les retours d'erreur avec message
	 *
	 * @param string $err
	 */
	public function setError($err){
		if(!empty($err)){
			$this->error = $err;
			$this->errors[] = $this->error;
		}
	}

	/**
	 * @return bool|string $url
	 */
	public function getWebHostTargetUrl(){
		global $conf;

		if(empty($conf->global->WEBOBSERVER_WEBHOST_URL)) return false;

		$url = $conf->global->WEBOBSERVER_WEBHOST_URL;

		if(filter_var($url, FILTER_VALIDATE_URL)){
			return $url;
		}
		else{
			return false;
		}
	}

}
