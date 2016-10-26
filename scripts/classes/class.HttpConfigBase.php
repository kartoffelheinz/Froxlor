<?php

/**
 * This file is part of the Froxlor project.
* Copyright (c) 2016 the Froxlor Team (see authors).
*
* For the full copyright and license information, please view the COPYING
* file that was distributed with this source code. You can also view the
* COPYING file online at http://files.froxlor.org/misc/COPYING.txt
*
* @copyright  (c) the authors
* @author     Froxlor team <team@froxlor.org> (2016-)
* @license    GPLv2 http://files.froxlor.org/misc/COPYING.txt
* @package    Cron
*
*/

/**
 * Class HttpConfigBase
*
* Base class for all HTTP server configs
*/
abstract class HttpConfigBase
{

	/**
	 * logger-object
	 *
	 * @var FroxlorLogger
	 */
	protected $logger = null;

	/**
	 * idnaconvert-wrapper object
	 *
	 * @var idna_convert_wrapper
	 */
	protected $idnaConvert = null;

	/**
	 * list of vhost with filename as index as its content
	 *
	 * @var array
	 */
	protected $vhosts = array();

	/**
	 * list of files with directory options (apache-only)
	 *
	 * @var array
	 */
	protected $diroptions_data = array();

	/**
	 * list of files with directory protection data
	 *
	 * @var array
	 */
	protected $htpasswds_data = array();

	/**
	 * flag whether the current customer is deactived
	 *
	 * @var bool
	 */
	protected $_deactivated = false;

	/**
	 * calling class
	 *
	 * @var string
	 */
	private $_caller = null;

	public function __construct($logger, $idnaConvert, $caller)
	{
		$this->logger = $logger;
		$this->idnaConvert = $idnaConvert;
		$this->_caller = $caller;
	}

	/**
	 * reload webserver and if necessary the used php-interface
	 */
	public function reload()
	{
		/**
		 * reload php-interface
		 */
		if (Settings::Get('system.phpreload_command') != '' && (int) Settings::Get('phpfpm.enabled') == 0 && (int) Settings::Get('system.mod_fcgid') == 0) {
			$this->logger->logAction(CRON_ACTION, LOG_INFO, $this->_caller . '::reload: restarting php processes');
			safe_exec(Settings::Get('system.phpreload_command'));
		} elseif ((int) Settings::Get('phpfpm.enabled') == 1) {
			$this->logger->logAction(CRON_ACTION, LOG_INFO, $this->_caller . '::reload: reloading php-fpm');
			safe_exec(escapeshellcmd(Settings::Get('phpfpm.reload')));
		}

		/**
		 * reload webserver itself
		 */
		$this->logger->logAction(CRON_ACTION, LOG_INFO, $this->_caller . '::reload: reloading ' . $this->_caller);
		safe_exec(Settings::Get('system.apachereload_command'));
	}

	/**
	 * We write the configs
	 */
	public function writeConfigs()
	{
		// Write diroptions
		$this->logger->logAction(CRON_ACTION, LOG_INFO, $this->_caller . "::writeConfigs: rebuilding " . Settings::Get('system.apacheconf_diroptions'));

		if (count($this->diroptions_data) > 0) {
			$optsDir = new frxDirectory(Settings::Get('system.apacheconf_diroptions'));
			if (! $optsDir->isConfigDir()) {
				// Save one big file
				$diroptions_file = '';

				foreach ($this->diroptions_data as $diroptions_filename => $diroptions_content) {
					$diroptions_file .= $diroptions_content . "\n\n";
				}

				$diroptions_filename = Settings::Get('system.apacheconf_diroptions');

				// Apply header
				$diroptions_file = '# ' . basename($diroptions_filename) . "\n" . '# Created ' . date('d.m.Y H:i') . "\n" . '# Do NOT manually edit this file, all changes will be deleted after the next domain change at the panel.' . "\n" . "\n" . $diroptions_file;
				$diroptions_file_handler = fopen($diroptions_filename, 'w');
				fwrite($diroptions_file_handler, $diroptions_file);
				fclose($diroptions_file_handler);
			} else {
				if (! file_exists(Settings::Get('system.apacheconf_diroptions'))) {
					$this->logger->logAction(CRON_ACTION, LOG_NOTICE, $this->_caller . '::writeConfigs: mkdir ' . escapeshellarg(makeCorrectDir(Settings::Get('system.apacheconf_diroptions'))));
					safe_exec('mkdir -p ' . escapeshellarg(makeCorrectDir(Settings::Get('system.apacheconf_diroptions'))));
				}

				// Write a single file for every diroption
				foreach ($this->diroptions_data as $diroptions_filename => $diroptions_file) {
					$this->known_diroptionsfilenames[] = basename($diroptions_filename);

					// Apply header
					$diroptions_file = '# ' . basename($diroptions_filename) . "\n" . '# Created ' . date('d.m.Y H:i') . "\n" . '# Do NOT manually edit this file, all changes will be deleted after the next domain change at the panel.' . "\n" . "\n" . $diroptions_file;
					$diroptions_file_handler = fopen($diroptions_filename, 'w');
					fwrite($diroptions_file_handler, $diroptions_file);
					fclose($diroptions_file_handler);
				}
			}
		}

		// Write htpasswds
		$this->logger->logAction(CRON_ACTION, LOG_INFO, $this->_caller . "::writeConfigs: rebuilding " . Settings::Get('system.apacheconf_htpasswddir'));

		if (count($this->htpasswds_data) > 0) {
			if (! file_exists(Settings::Get('system.apacheconf_htpasswddir'))) {
				$umask = umask();
				umask(0000);
				mkdir(Settings::Get('system.apacheconf_htpasswddir'), 0751);
				umask($umask);
			}

			$htpasswdDir = new frxDirectory(Settings::Get('system.apacheconf_htpasswddir'));
			if ($htpasswdDir->isConfigDir(true)) {
				foreach ($this->htpasswds_data as $htpasswd_filename => $htpasswd_file) {
					$htpasswd_file_handler = fopen($htpasswd_filename, 'w');
					// Filter duplicate pairs of username and password
					$htpasswd_file = implode("\n", array_unique(explode("\n", $htpasswd_file)));
					fwrite($htpasswd_file_handler, $htpasswd_file);
					fclose($htpasswd_file_handler);
				}
			} else {
				$this->logger->logAction(CRON_ACTION, LOG_WARNING, 'WARNING!!! ' . Settings::Get('system.apacheconf_htpasswddir') . ' is not a directory. htpasswd directory protection is disabled!!!');
			}
		}

		// Write virtualhosts
		$this->logger->logAction(CRON_ACTION, LOG_INFO, $this->_caller . "::writeConfigs: rebuilding " . Settings::Get('system.apacheconf_vhost'));

		if (count($this->vhosts) > 0) {
			$vhostDir = new frxDirectory(Settings::Get('system.apacheconf_vhost'));
			if (! $vhostDir->isConfigDir()) {
				// Save one big file
				$vhosts_file = '';

				// sort by filename so the order is:
				// 1. subdomains x-29
				// 2. subdomains as main-domains 30
				// 3. main-domains 35
				// #437
				ksort($this->vhosts);

				foreach ($this->vhosts as $vhosts_filename => $vhost_content) {
					$vhosts_file .= $vhost_content . "\n\n";
				}

				// apache-only: Include diroptions file in case it exists
				if (Settings::Get('system.webserver') == "apache2" && file_exists(Settings::Get('system.apacheconf_diroptions'))) {
					$vhosts_file .= "\n" . 'Include ' . Settings::Get('system.apacheconf_diroptions') . "\n\n";
				}

				$vhosts_filename = Settings::Get('system.apacheconf_vhost');

				// Apply header
				$vhosts_file = '# ' . basename($vhosts_filename) . "\n" . '# Created ' . date('d.m.Y H:i') . "\n" . '# Do NOT manually edit this file, all changes will be deleted after the next domain change at the panel.' . "\n" . "\n" . $vhosts_file;
				$vhosts_file_handler = fopen($vhosts_filename, 'w');
				fwrite($vhosts_file_handler, $vhosts_file);
				fclose($vhosts_file_handler);
			} else {
				if (! file_exists(Settings::Get('system.apacheconf_vhost'))) {
					$this->logger->logAction(CRON_ACTION, LOG_NOTICE, $this->_caller . '::writeConfigs: mkdir ' . escapeshellarg(makeCorrectDir(Settings::Get('system.apacheconf_vhost'))));
					safe_exec('mkdir -p ' . escapeshellarg(makeCorrectDir(Settings::Get('system.apacheconf_vhost'))));
				}

				// Write a single file for every vhost
				foreach ($this->vhosts as $vhosts_filename => $vhosts_file) {
					$this->known_vhostfilenames[] = basename($vhosts_filename);

					// Apply header
					$vhosts_file = '# ' . basename($vhosts_filename) . "\n" . '# Created ' . date('d.m.Y H:i') . "\n" . '# Do NOT manually edit this file, all changes will be deleted after the next domain change at the panel.' . "\n" . "\n" . $vhosts_file;
					$vhosts_file_handler = fopen($vhosts_filename, 'w');
					fwrite($vhosts_file_handler, $vhosts_file);
					fclose($vhosts_file_handler);
				}
			}
		}
	}

	protected function getPhpOpenBasedirAppendValue($domain)
	{
		$_phpappendopenbasedir = "";
		if ($domain['openbasedir_path'] == '1' || strstr($domain['documentroot'], ":") !== false) {
			$_phpappendopenbasedir = appendOpenBasedirPath($domain['customerroot'], true);
		} else {
			$_phpappendopenbasedir = appendOpenBasedirPath($domain['documentroot'], true);
		}

		$_custom_openbasedir = explode(':', Settings::Get('system.phpappendopenbasedir'));
		foreach ($_custom_openbasedir as $cobd) {
			$_phpappendopenbasedir .= appendOpenBasedirPath($cobd);
		}
		return $_phpappendopenbasedir;
	}

	/**
	 * Get the filename for the virtualhost
	 *
	 * @param array $domain
	 * @param bool $ssl_vhost
	 *
	 * @return string filename
	 */
	protected function getVhostFilename($domain, $ssl_vhost = false)
	{
		if ((int) $domain['parentdomainid'] == 0 && isCustomerStdSubdomain((int) $domain['id']) == false && ((int) $domain['ismainbutsubto'] == 0 || domainMainToSubExists($domain['ismainbutsubto']) == false)) {
			$vhost_no = '35';
		} elseif ((int) $domain['parentdomainid'] == 0 && isCustomerStdSubdomain((int) $domain['id']) == false && (int) $domain['ismainbutsubto'] > 0) {
			$vhost_no = '30';
		} else {
			// number of dots in a domain specifies it's position (and depth of subdomain) starting at 29 going downwards on higher depth
			$vhost_no = (string) (30 - substr_count($domain['domain'], ".") + 1);
		}

		if ($ssl_vhost === true) {
			$vhost_filename = makeCorrectFile(Settings::Get('system.apacheconf_vhost') . '/' . $vhost_no . '_froxlor_ssl_vhost_' . $domain['domain'] . '.conf');
		} else {
			$vhost_filename = makeCorrectFile(Settings::Get('system.apacheconf_vhost') . '/' . $vhost_no . '_froxlor_normal_vhost_' . $domain['domain'] . '.conf');
		}

		return $vhost_filename;
	}

	/**
	 *
	 * @param string $number
	 * @param string $type
	 * @param string $content
	 *
	 * @return string
	 */
	protected function getCustomVhostFilename($number = '01', $type = 'ipandport', $content = null)
	{
		return makeCorrectFile(Settings::Get('system.apacheconf_vhost') . '/' . $number . '_froxlor_' . $type . (!empty($content) ? '_' . $content : "") . '.conf');
	}

	/**
	 * process special config as template, by substituting {VARIABLE} with the
	 * respective value.
	 *
	 * The following variables are known at the moment:
	 *
	 * {DOMAIN} - domain name
	 * {IP} - IP for this domain
	 * {PORT} - Port for this domain
	 * {CUSTOMER} - customer name
	 * {IS_SSL} - evaluates to 'ssl' if domain/ip is ssl, otherwise it is an empty string
	 * {DOCROOT} - document root for this domain
	 *
	 * @param
	 *        	$template
	 * @return string
	 */
	protected function processSpecialConfigTemplate($template, $domain, $ip, $port, $is_ssl_vhost)
	{
		$templateVars = array(
			'DOMAIN' => $domain['domain'],
			'CUSTOMER' => $domain['loginname'],
			'IP' => $ip,
			'PORT' => $port,
			'SCHEME' => ($is_ssl_vhost) ? 'https' : 'http',
			'DOCROOT' => $domain['documentroot']
		);
		return replace_variables($template, $templateVars);
	}

	protected function getMyPath($ip_port = null)
	{
		if (! empty($ip_port) && $ip_port['docroot'] == '') {
			if (Settings::Get('system.froxlordirectlyviahostname')) {
				$mypath = makeCorrectDir(dirname(dirname(dirname(__FILE__))));
			} else {
				$mypath = makeCorrectDir(dirname(dirname(dirname(dirname(__FILE__)))));
			}
		} else {
			// user-defined docroot, #417
			$mypath = makeCorrectDir($ip_port['docroot']);
		}
		return $mypath;
	}

	protected function checkAlternativeSslPort()
	{
		// We must not check if our port differs from port 443,
		// but if there is a destination-port != 443
		$_sslport = '';
		// This returns the first port that is != 443 with ssl enabled,
		// ordered by ssl-certificate (if any) so that the ip/port combo
		// with certificate is used
		$ssldestport_stmt = Database::prepare("
			SELECT `ip`.`port` FROM " . TABLE_PANEL_IPSANDPORTS . " `ip`
			WHERE `ip`.`ssl` = '1'  AND `ip`.`port` != 443
			ORDER BY `ip`.`ssl_cert_file` DESC, `ip`.`port` LIMIT 1;
		");
		$ssldestport = Database::pexecute_first($ssldestport_stmt);

		if ($ssldestport['port'] != '') {
			$_sslport = ":" . $ssldestport['port'];
		}

		return $_sslport;
	}

	protected function froxlorVhostHasLetsEncryptCert()
	{
		// check whether we have an entry with valid certificates which just does not need
		// updating yet, so we need to skip this here
		$froxlor_ssl_settings_stmt = Database::prepare("
			SELECT * FROM `" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "` WHERE `domainid` = '0'
		");
		$froxlor_ssl = Database::pexecute_first($froxlor_ssl_settings_stmt);
		if ($froxlor_ssl && ! empty($froxlor_ssl['ssl_cert_file'])) {
			return true;
		}
		return false;
	}

	protected function froxlorVhostLetsEncryptNeedsRenew()
	{
		$froxlor_ssl_settings_stmt = Database::prepare("
			SELECT * FROM `" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "`
			WHERE `domainid` = '0' AND
			(`expirationdate` < DATE_ADD(NOW(), INTERVAL 30 DAY) OR `expirationdate` IS NULL)
		");
		$froxlor_ssl = Database::pexecute_first($froxlor_ssl_settings_stmt);
		if ($froxlor_ssl && ! empty($froxlor_ssl['ssl_cert_file'])) {
			return true;
		}
		return false;
	}
}
