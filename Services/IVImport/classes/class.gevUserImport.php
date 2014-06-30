<?php
/* Copyright (c) 1998-2014 ILIAS open source, Extended GPL, see docs/LICENSE */

require_once("./Services/User/classes/class.ilObjUser.php");


/**
 * Class gevUserImport
 *
 * Helper class to import Generali users.
 *
 * @author: Fabian Kochem <fabian.kochem@concepts-and-training.de>
 *
 */
class gevUserImport {
	private $mysql, $ilDB;
	static $instance = null;

	protected function __construct($mysql, $ilDB) {
		$this->mysql = $mysql;
		$this->ilDB = $ilDB;
	}

	public function getInstance($mysql, $ilDB) {
		if (self::$instance !== null) {
			return self::$instance;
		}

		self::$instance = new self($mysql, $ilDB);
		return self::$instance;
	}


	public function register($stelle, $email) {
		$username = $email;

		if ($this->token_exists_for_email($email)) {
			if ($this->token_exists_for_stelle($stelle)) {
				$this->send_confirmation_email($token);
				return false;
			} else {
				$username = $this->add_counter_to_username($username);
			}
		}

		$shadow_user = $this->get_shadow_user($stelle, $email);
		if ($shadow_user !== false) {
			$token = $this->generate_confirmation_token();
			$this->save_token($token, $username, $stelle, $email);
			$this->send_confirmation_email($token, $username, $email);
		}

		return false;
	}

	public function activate($token) {
		$token_data = $this->get_token_data($token);

		if ($token_data === false) {
			return 'Token not found.';
		}

		if ($this->token_was_used($token)) {
			return 'Token has been used already.';
		}

		$username = $token_data['username'];
		$stelle = $token_data['stelle'];
		$email = $token_data['email'];

		$shadow_user = $this->get_shadow_user($stelle, $email);

		if ($shadow_user === false) {
			return 'Shadow user not found.';
		}

		$ilias_user = $this->create_ilias_user($username, $shadow_user, $token);
		if ($ilias_user === false) {
			return 'User already exists.';
		}

		$ilias_user_id = $ilias_user->getId();
		$this->set_ilias_user_id($shadow_user['id'], $ilias_user_id);

		$this->log_user_in($ilias_user_id);
		$this->set_token_used_field($token);
	}


	private function get_shadow_user($stellennummer, $email) {
		$sql = "
			SELECT
				*
			FROM
				`ivimport_adp`
			WHERE
				`stelle`=" . $this->ilDB->quote($stellennummer, "text") . "
			AND
				`email`=" . $this->ilDB->quote($email, "text") . "
		";
		$result = mysql_query($sql, $this->mysql);

		if ((!$result) || (mysql_num_rows($result) !== 1)) {
			return false;
		}

		while ($row = mysql_fetch_assoc($result)) {
			return $row;
		}
	}

	private function create_ilias_user($username, $shadow_user, $token) {
		if (ilObjUser::_lookupId($username)) {
			return false;
		}

		$user = new ilObjUser();
		$user->setLogin($username);
		$user->setPasswd($token);

		$user->setLastname($shadow_user['nachname']);
		$user->setFirstname($shadow_user['vorname']);
		$user->setEmail($shadow_user['email']);
		$user->setGender($shadow_user['geschlecht']);

		$user->setActive(true);
		$user->setTimeLimitUnlimited(true);

		$user->setBirthday($shadow_user['geburtsdatum']);
		$user->setZipcode($shadow_user['plz']);
		$user->setStreet($shadow_user['street']);
		$user->setPhoneOffice($shadow_user['telefon']);
		$user->setFax($shadow_user['fax']);

		if ($shadow_user['land'] == 'Deutschland') {
			$user->setSelectedCountry('DE');
		}

		$user->create();
		$user->saveAsNew();
		return $user;
	}


	private function send_confirmation_email($token) {
		$this->set_email_sent_field($token);
	}

	private function set_email_sent_field($token) {
		$sql = "
			UPDATE 
				`gev_user_reg_tokens`
			SET
				`email_sent`=NOW()
			WHERE
				`token`=" . $this->ilDB->quote($token, "text") . ";
		";

		$result = $this->ilDB->query($sql);
		return $this->ilDB->numRows($result) === 1;
	}

	public function set_token_used_field($token) {
		$sql = "
			UPDATE 
				`gev_user_reg_tokens`
			SET
				`token_used`=NOW()
			WHERE
				`token`=" . $this->ilDB->quote($token, "text") . ";
		";

		$result = $this->ilDB->query($sql);
		return $this->ilDB->numRows($result) === 1;
	}

	private function add_counter_to_username($username) {
		$wildcard = $username . "%";
		$sql = "
			SELECT
				`username`
			FROM 
				`gev_user_reg_tokens`
			WHERE
				`username` LIKE " . $this->ilDB->quote($wildcard, "text") . "
		";

		$result = $this->ilDB->query($sql);

		$highest_count = 1;

		while ($row = $this->ilDB->fetchAssoc($result)) {
			$username = $row['username'];
			if (preg_match("/(.*[^0-9])([0-9]*)$/", $username, $match)) {
				$email = $match[1];
				$counter = $match[2];

				if (($counter) && ($counter >= $highest_count)) {
					$highest_count = $counter + 1;
				}
			}
		}

		return $email . $highest_count;
	}

	private function set_ilias_user_id($shadow_user_id, $ilias_user_id) {
		$sql = "
			UPDATE 
				`ivimport_adp`
			SET
				`ilias_id`=" . $this->ilDB->quote($ilias_user_id, "text") . "
			WHERE
				`id`=" . $this->ilDB->quote($shadow_user_id, "text") . "
		";

		return mysql_query($sql, $this->mysql) === 1;
	}


	private function generate_confirmation_token($max_attempts=10) {
		$found_token = false;
		$attempt = 0;

		while (!$found_token) {
			$token = md5(rand());
			if ($this->token_is_usable($token)) {
				$found_token = true;
			}

			if ($attempt > $max_attempts) {
				die('Number of maximum attempts has been reached.');
			}
			$attempt++;
		}

		return $token;
	}

	private function token_is_usable($token) {
		$sql = "
			SELECT
				* 
			FROM
				`gev_user_reg_tokens` 
			WHERE
				`token`=" . $this->ilDB->quote($token, "text") . ";
		";

		$result = $this->ilDB->query($sql);
		return $this->ilDB->numRows($result) === 0;
	}

	private function token_was_used($token) {
		$sql = "
			SELECT
				`token_used`
			FROM
				`gev_user_reg_tokens` 
			WHERE
				`token`=" . $this->ilDB->quote($token, "text") . "
			AND
				`token_used` IS NOT NULL;
		";

		$result = $this->ilDB->query($sql);
		return $this->ilDB->numRows($result) > 0;
	}

	private function token_exists_for_email($email) {
		$sql = "
			SELECT
				* 
			FROM
				`gev_user_reg_tokens` 
			WHERE
				`email`=" . $this->ilDB->quote($email, "text") . ";
		";

		$result = $this->ilDB->query($sql);
		return $this->ilDB->numRows($result) > 0;
	}

	private function token_exists_for_stelle($stelle) {
		$sql = "
			SELECT
				* 
			FROM
				`gev_user_reg_tokens` 
			WHERE
				`stelle`=" . $this->ilDB->quote($stelle, "text") . ";
		";

		$result = $this->ilDB->query($sql);
		return $this->ilDB->numRows($result) > 0;
	}

	private function get_token_data($token) {
		$sql = "
			SELECT
				*
			FROM
				`gev_user_reg_tokens`
			WHERE
				`token`=" . $this->ilDB->quote($token, "text") . "
		";

		$result = $this->ilDB->query($sql);

		if ((!$result) || ($this->ilDB->numRows($result) !== 1)) {
			return false;
		}

		while ($row = $this->ilDB->fetchAssoc($result)) {
			return $row;
		}
	}

	private function save_token($token, $username, $stelle, $email) {
		$sql = "
			INSERT INTO
				`gev_user_reg_tokens`
			(
				`token` ,
				`stelle` ,
				`username` ,
				`email`
			)
			VALUES (
				" . $this->ilDB->quote($token, "text") . ",
				" . $this->ilDB->quote($stelle, "text") . ",
				" . $this->ilDB->quote($username, "text") . ",
				" . $this->ilDB->quote($email, "text") . "
			);
		";

		return $this->ilDB->query($sql) === 1;
	}

	private function log_user_in($ilias_user_id) {

	}
}

?>
