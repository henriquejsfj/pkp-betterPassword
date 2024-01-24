<?php

/**
 * @file plugins/generic/betterPassword/features/LimitReuse.inc.php
 *
 * Copyright (c) 2021 University of Pittsburgh
 * Distributed under the GNU GPL v2 or later. For full terms see the file docs/COPYING.
 *
 * @class LimitReuse
 * @ingroup plugins_generic_betterPassword
 *
 * @brief Implements the feature to disable reusing old passwords
 */
namespace APP\plugins\generic\betterPassword\features;

use PKP\plugins\Hook;
use PKP\core\PKPApplication;
use PKP\form\validation\FormValidatorCustom;
use PKP\security\Validation;
use PKP\user\User;
use PKP\user\Repository;
use PKP\db\DAORegistry;
use APP\core\Application;
use APP\plugins\generic\betterPassword\BetterPasswordPlugin;
use APP\facades\Repo;

class LimitReuse {
	/** @var BetterPasswordPlugin */
	private $_plugin;

	/** @var int Max reusable passwords */
	private $_maxReusablePasswords;

	/** @var bool Flag to avoid an infinite loop in the handler */
	private $_handledPasswordUpdate;

	/**
	 * Constructor
	 * @param BetterPasswordPlugin $plugin
	 */
	public function __construct(BetterPasswordPlugin $plugin) {
		$this->_plugin = $plugin;
		$this->_maxReusablePasswords = (int) $plugin->getSetting(PKPApplication::CONTEXT_SITE, 'betterPasswordInvalidationPasswords');
		if (!$this->_maxReusablePasswords) {
			return;
		}

		//Hook::add('Schema::get::' . $dao->schema, $this->addToSchema(...));
		$userDao = Repo::user()->dao;

		//Hook::add('Schema::get::user', [$this, 'addToSchema']);
		Hook::add('Schema::get::' . $userDao->schema, [$this, 'addToSchema'], Hook::SEQUENCE_CORE); //user may be needed, check
		Hook::add('Schema::get::storedPassword', [$this, 'setStoredPasswordSchema']);
		Hook::add('changepasswordform::execute', [$this, 'rememberPasswords']); //new methods
		
		Hook::add('loginchangepasswordform::Constructor', [$this, 'passwordChangeValidation']);
		Hook::add('changepasswordform::Constructor', [$this, 'passwordChangeValidation']);

		//$this->_saveUserPasswords(); //replace these with our hooks
		//$this->_addPasswordChangeValidation();
	}

	/**
	 * Register a hook to validate reused passwords
	 */
	private function _addPasswordChangeValidation() : void {
		/*foreach(['loginchangepasswordform::Constructor', 'changepasswordform::Constructor'] as $hook) {
			Hook::add($hook, function ($hook, $args) {
				//debug_to_console('test1');
				/** @var Form $form 
				[$form] = $args;
				$form->addCheck(new FormValidatorCustom(
					$form, 'password', 'required', 'plugins.generic.betterPassword.validation.betterPasswordPasswordReused',
					function ($password) use ($form) {
						$user = Application::get()->getRequest()->getUser();
						if (!$user) {
							/** @var UserDAO 
							//$userDao = DAORegistry::getDAO('UserDAO');
							//$user = $userDao->getByUsername($form->getData('username'));
							//$temp = $form->getData('username'); //remove
							$user = Repo::user()->getByUsername($form->getData('username'));
						}
						foreach ($this->_getPasswords($user) as $previousPassword) { //coming up empty, not saving previous passwords
							// Check if an old password matches with the new
							if (Validation::verifyPassword($user->getUsername(), $password, $previousPassword, $rehash)) {
								return false;
							}
						}
						return true;
					}
				));
			});
		}*/
	}

	/**
	 * Register a hook to save new passwords
	 */
	private function _saveUserPasswords() : void { //note this section is suplurfluous, and working to remove it
			/*$handler = function ($hook, $args) {
			[$page, $operation] = $args;
			if (!in_array($page, ['tab.user.ProfileTabHandler', 'login']) || $operation !== 'savePassword') {
				return;
			}
			Hook::add('User::edit', function ($hook, $args) {
				//debug_to_console('test2');
				// Avoid an infinite loop after updating itself
				if ($this->_handledPasswordUpdate) {
					$user = $args[0];
					$hold = $user->getAllData();
					return;
				}
				//[, [$username, $password]] = $args;
				//echo(var_export($args));
				/** @var UserDao 
				//$userDao = DAORegistry::getDAO('UserDAO');
				//$user = $userDao->getByUsername($username);
				$user = $args[0];
				$currentPassword = $user->getPassword();
				//$userName = $args[0]->getUsername();
				//$user = Repo::user()->getByUsername($username);
				$user = $this->_addPassword($user, $currentPassword);
				$this->_handledPasswordUpdate = true;
				//$userDao->updateObject($user);
				Repo::user()->edit($user);
			});
		}; 
		
		//Hook::add('changepasswordform::execute', [$this, 'rememberPasswords']);
		Hook::add('changepasswordform::execute', [$this, 'rememberPasswords']);
		//Hook::add('LoadComponentHandler', $handler);
		//Hook::add('LoadHandler', $handler);*/
	}

	public function addToSchema($hook, $args) {
		$user = $args[0];

		$user->properties->{$this->_plugin->getSettingsName()."::lastPasswords"} = (object) [
			'type' => 'string',
			'apiSummary' => false,
			//'multilingual' => false,
			'validation' => ['nullable']
		];

		$user->properties->{$this->_plugin->getSettingsName()."::lastPasswordsUpdate"} = (object) [
			'type' => 'string',
			'apiSummary' => false,
			//'multilingual' => false,
			'validation' => ['nullable', 'date:Y-m-d H:i:s']
		];

		return false;
	}
	//takes in a user and a password in order to change the user, calling _addPassword
	public function rememberPasswords($hook, $args) {
		if ($hook == 'changepasswordform::execute' && get_class($args[0]) == 'PKP\user\form\ChangePasswordForm') {
			$user = $args[0]->getUser();
			$password = $user->getData('password'); //this should be the hashed password
			//hash tests
			//$test = Validation::encryptCredentials($user->getUsername(), '123456');
			//$testPassword = Validation::encryptCredentials($user->getUsername(), 'password');
			//$test654321= Validation::encryptCredentials($user->getUsername(), '654321');
			//$rehash = null;
			//$test = Validation::verifyPassword($user->getData('userName'), 'password', '$2y$10$k6aIod/hEcY.lp8AVgH3HeAIpJVVlTs51iFBe.lfq/VR0cbtEQqi2', $rehash);
			//$test2 = Validation::verifyPassword($user->getData('userName'), 'password', '$2y$10$wBThl3/VznrITyZgNTKr8eVbXYppliK3ruNr7LDyxK1RR2f7Ywp1C', $rehash);
			//$test3 = Validation::verifyPassword($user->getData('userName'), 'password', '$2y$10$C1WYUTshxNW9FjdYfemug.JpnMUIImp6UHR4fORXPwQtpATdD7gDW', $rehash);

			$user = $this->_addPassword($user, $password);

			//Repo::user()->edit($user);
			//Repo::user()->edit($user);
			//below is new, check if above lines are neccesary

		}
		return false;
	}
	//check logic flow of forms, at what point must each method be called, validator looks at past passwords to compare, then the execution method calls the data change events to store password history
	public function passwordChangeValidation($hook, $args) {
		$form = $args[0];
		$newCheck = new FormValidatorCustom($form, 'password', 'required', 'plugins.generic.betterPassword.validation.betterPasswordPasswordReused', function ($password) use ($form) {
			 return $this->passwordCompare($password, $form);
		}); //$this->passwordCompare());
		$form->addCheck($newCheck);
		return false;
	}

	public function passwordCompare($password, $form) {
		$user = Application::get()->getRequest()->getUser();
		if (!$user) {
			$user = Repo::user()->getByUsername($form->getData('username'));
		}
		foreach ($this->_getPasswords($user) as $previousPassword) {
			if (Validation::verifyPassword($user->getUsername(), $password, $previousPassword, $rehash)) { //ask about rehash, only instance of it
				return false;
			}
		}
		return true;
	}

	/**
	 * Retrieve the hashed user passwords
	 * @param User $user
	 * @return ?string
	 */
	private function _getPasswords(User $user) : array {
		//$passwords = json_decode($user->getData("{$this->_plugin->getSettingsName()}::lastPasswords")) ?? [];
		$hold = $this->_plugin->getSettingsName();
		$hold2 = $user->getData("{$hold}::lastPasswords");
		$hold3 = json_decode($hold2);
		$hold4 = $user->getAllData();
		$passwords = $hold3 ??  [];
		return array_slice($passwords, 0, $this->_maxReusablePasswords);
	}

	/**
	 * Add a user password and refresh the last updated timestamp
	 * @param User $user
	 * @param string $password
	 */
	private function _addPassword(User $user, string $password) : array { //password here needs to be a hash
		//@var $storedPasswordsDao StoredPasswordsDAO
		/*
		$storedPasswordsDao = DAORegistry::getDAO('StoredPasswordsDAO');
		$storedPasswords = $storedPasswordsDao->placeholder($user->getId(), $user->getPassword());
		$mostRecentPasswords = $storedPasswordsDao->getMostRecentPasswords($storedPasswords);
		//$countingValue = count($ourArray);
		if (count($mostRecentPasswords) <= $this->_maxReusablePasswords) {
			//$mostRecentPasswords = implode();
			$storedPasswordsDao->updatePasswords($storedPasswords);
		}
		else {

		}
		*/

		$storedPasswordsDao = DAORegistry::getDAO('StoredPasswordsDAO');
		$storedPasswords = $storedPasswordsDao->placeholder($user->getId(), $user->getPassword());
		$totalPasswords = $storedPasswords->getPasswords();
		if (count($totalPasswords) < $this->_maxReusablePasswords) {
			array_push($totalPasswords, $password);
			$storedPasswords->setPasswords($totalPasswords);
			$storedPasswordsDao->updateObject($storedPasswords);
		}
		else {
			array_shift($totalPasswords);
			array_push($totalPasswords, $password);
			$storedPasswords->setPasswords($totalPasswords);
			$storedPasswordsDao->updateObject($storedPasswords);
		}
		return $totalPasswords;
		/*
		$newUser = Repo::user()->get($user->getId());
		$passwords = $this->_getPasswords($user);
		array_unshift($passwords, $password);
		$passwords = array_slice($passwords, 0, $this->_maxReusablePasswords);
		$user->setData("previousPasswords", json_encode($passwords)); //check here, passwords being decoded but we want them to remain as hashes, need to keep conversions of json and such in mind for future use
		$hold = $user->getAllData();
		//$user->setData("{$this->_plugin->getSettingsName()}::lastPasswordUpdate", (new \DateTime())->format('c')); maybe not needed here, only in Force Expiration
		return $user;
		*/
	}

	public function setStoredPasswordSchema ($hook, $args) {
		$schema = $args[0];
		$schemaFile = sprintf('%s/plugins/generic/betterPassword/schemas/storedPassword.json', BASE_SYS_DIR);
        if (file_exists($schemaFile)) {
            $temp = json_decode(file_get_contents($schemaFile));
			foreach ($temp as $key => $value) {
				$schema->$key = $value;
			}
			//$schema->test=true;
            if (!$schema) {
                throw new Exception('Schema failed to decode. This usually means it is invalid JSON. Requested: ' . $schemaFile . '. Last JSON error: ' . json_last_error());
            }
        } else {
            // allow plugins to create a custom schema and load it via hook
            $schema = new \stdClass();
        }
		return false;
	}
}
