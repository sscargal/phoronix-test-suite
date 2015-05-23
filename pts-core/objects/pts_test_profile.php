<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2008 - 2015, Phoronix Media
	Copyright (C) 2008 - 2015, Michael Larabel

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

class pts_test_profile extends pts_test_profile_parser
{
	public $test_installation = false;

	public function __construct($identifier = null, $override_values = null)
	{
		parent::__construct($identifier);

		if($override_values != null && is_array($override_values))
		{
			$this->xml_parser->overrideXMLValues($override_values);
		}

		if(PTS_IS_CLIENT && is_file($this->get_install_dir() . 'pts-install.xml'))
		{
			$this->test_installation = new pts_installed_test($this);
		}
	}
	public static function is_test_profile($identifier)
	{
		$identifier = pts_openbenchmarking::evaluate_string_to_qualifier($identifier, true, 'test');
		return $identifier != false && is_file(PTS_TEST_PROFILE_PATH . $identifier . '/test-definition.xml') ? $identifier : false;
	}
	public function get_resource_dir()
	{
		return PTS_TEST_PROFILE_PATH . $this->identifier . '/';
	}
	public function get_override_values()
	{
		return $this->xml_parser->getOverrideValues();
	}
	public function set_override_values($override_values)
	{
		if(is_array($override_values))
		{
			$this->xml_parser->overrideXMLValues($override_values);
		}
	}
	public function get_download_size($include_extensions = true, $divider = 1048576)
	{
		$estimated_size = 0;

		foreach(pts_test_install_request::read_download_object_list($this->identifier) as $download_object)
		{
			$estimated_size += $download_object->get_filesize();
		}

		if($include_extensions)
		{
			$extends = $this->get_test_extension();

			if(!empty($extends))
			{
				$test_profile = new pts_test_profile($extends);
				$estimated_size += $test_profile->get_download_size(true, 1);
			}
		}

		$estimated_size = $estimated_size > 0 && $divider > 1 ? round($estimated_size / $divider, 2) : 0;

		return $estimated_size;
	}
	public function get_environment_size($include_extensions = true)
	{
		$estimated_size = parent::get_environment_size();

		if($include_extensions)
		{
			$extends = $this->get_test_extension();

			if(!empty($extends))
			{
				$test_profile = new pts_test_profile($extends);
				$estimated_size += $test_profile->get_environment_size(true);
			}
		}

		return $estimated_size;
	}
	public function get_test_extensions_recursive()
	{
		// Process Extensions / Cascading Test Profiles
		$extensions = array();
		$extended_test = $this->get_test_extension();

		if(!empty($extended_test))
		{
			do
			{
				if(!in_array($extended_test, $extensions))
				{
					array_push($extensions, $extended_test);
				}

				$extended_test = new pts_test_profile_parser($extended_test);
				$extended_test = $extended_test->get_test_extension();
			}
			while(!empty($extended_test));
		}

		return $extensions;
	}
	public function get_dependency_names()
	{
		$dependency_names = array();
		$exdep_generic_parser = new pts_exdep_generic_parser();

		foreach($this->get_dependencies() as $dependency)
		{
			if($exdep_generic_parser->is_package($dependency))
			{
				$package_data = $exdep_generic_parser->get_package_data($dependency);
				array_push($dependency_names, $package_data['title']);
				break;
			}
		}

		return $dependency_names;
	}
	public function get_times_to_run()
	{
		$times_to_run = parent::get_times_to_run();

		if(($force_runs = pts_client::read_env('FORCE_TIMES_TO_RUN')) && is_numeric($force_runs))
		{
			$times_to_run = $force_runs;
		}

		if(($force_runs = pts_client::read_env('FORCE_MIN_TIMES_TO_RUN')) && is_numeric($force_runs) && $force_runs > $times_to_run)
		{
			$times_to_run = $force_runs;
		}

		$display_format = $this->get_display_format();
		if($times_to_run < 1 || (strlen($display_format) > 6 && substr($display_format, 0, 6) == 'MULTI_' || substr($display_format, 0, 6) == 'IMAGE_'))
		{
			// Currently tests that output multiple results in one run can only be run once
			$times_to_run = 1;
		}

		return $times_to_run;
	}
	public function get_estimated_run_time()
	{
		// get estimated run-time (in seconds)
		if($this->test_installation != false && is_numeric($this->test_installation->get_average_run_time()) && $this->test_installation->get_average_run_time() > 0)
		{
			$estimated_run_time = $this->test_installation->get_average_run_time();
		}
		else
		{
			$estimated_run_time = parent::get_estimated_run_time();
		}

		if($estimated_run_time < 2 && PTS_IS_CLIENT)
		{
			$identifier = explode('/', $this->get_identifier(false));
			$repo_index = pts_openbenchmarking::read_repository_index($identifier[0]);
			$estimated_run_time = isset($identifier[1]) && isset($repo_index['tests'][$identifier[1]]) && isset($repo_index['tests'][$identifier[1]]['average_run_time']) ? $repo_index['tests'][$identifier[1]]['average_run_time'] : 0;
		}

		return $estimated_run_time;
	}
	public function is_supported($report_warnings = true)
	{
		$test_supported = true;

		if(PTS_IS_CLIENT && pts_client::read_env('SKIP_TEST_SUPPORT_CHECKS'))
		{
			// set SKIP_TEST_SUPPORT_CHECKS=1 environment variable for debugging purposes to run tests on unsupported platforms
			return true;
		}
		else if($this->is_test_architecture_supported() == false)
		{
			PTS_IS_CLIENT && $report_warnings && pts_client::$display->test_run_error($this->get_identifier() . ' is not supported on this architecture: ' . phodevi::read_property('system', 'kernel-architecture'));
			$test_supported = false;
		}
		else if($this->is_test_platform_supported() == false)
		{
			PTS_IS_CLIENT && $report_warnings && pts_client::$display->test_run_error($this->get_identifier() . ' is not supported by this operating system: ' . phodevi::operating_system());
			$test_supported = false;
		}
		else if($this->is_core_version_supported() == false)
		{
			PTS_IS_CLIENT && $report_warnings && pts_client::$display->test_run_error($this->get_identifier() . ' is not supported by this version of the Phoronix Test Suite: ' . PTS_VERSION);
			$test_supported = false;
		}
		else if(PTS_IS_CLIENT && ($custom_support_check = $this->custom_test_support_check()) !== true)
		{
			// A custom-self-generated error occurred, see code comments in custom_test_support_check()
			PTS_IS_CLIENT && $report_warnings && is_callable(array(pts_client::$display, 'test_run_error')) && pts_client::$display->test_run_error($this->get_identifier() . ': ' . $custom_support_check);
			$test_supported = false;
		}
		else if(PTS_IS_CLIENT)
		{
			foreach($this->extended_test_profiles() as $extension)
			{
				if($extension->is_supported($report_warnings) == false)
				{
					$test_supported = false;
					break;
				}
			}
		}

		return $test_supported;
	}
	public function custom_test_support_check()
	{
		/*
		As of Phoronix Test Suite 4.4, the software will check for the presence of a 'support-check' file.
		Any test profile can optionally include a support-check.sh file to check for arbitrary commands not covered by
		the rest of the PTS testing architecture, e.g. to check for the presence of systemd on the target system. If
		the script finds that the system is incompatible with the test, it can write a custom error message to the file
		specified by the $TEST_CUSTOM_ERROR environment variable. If the $TEST_CUSTOM_ERROR target is written to, the PTS
		client will abort the test installation with the specified error message.
		*/

		$support_check_file = $this->get_resource_dir() . 'support-check.sh';

		if(PTS_IS_CLIENT && is_file($support_check_file))
		{
			$environment['TEST_CUSTOM_ERROR'] = pts_client::temporary_directory() . '/PTS-' . $this->get_identifier_base_name() . '-' . rand(1000, 9999);
			$support_check = pts_tests::call_test_script($this, 'support-check', null, null, $environment, false);

			if(is_file($environment['TEST_CUSTOM_ERROR']))
			{
				$support_result = pts_file_io::file_get_contents($environment['TEST_CUSTOM_ERROR']);
				pts_file_io::delete($environment['TEST_CUSTOM_ERROR']);
				return $support_result;
			}
		}

		return true;
	}
	public function is_test_architecture_supported()
	{
		// Check if the system's architecture is supported by a test
		$supported = true;
		$archs = $this->get_supported_architectures();

		if(!empty($archs))
		{
			$supported = phodevi::cpu_arch_compatible($archs);
		}

		return $supported;
	}
	public function is_core_version_supported()
	{
		// Check if the test profile's version is compatible with pts-core
		$core_version_min = parent::requires_core_version_min();
		$core_version_max = parent::requires_core_version_max();

		return $core_version_min <= PTS_CORE_VERSION && $core_version_max > PTS_CORE_VERSION;
	}
	public function is_test_platform_supported()
	{
		// Check if the system's OS is supported by a test
		$supported = true;

		$platforms = $this->get_supported_platforms();

		if(!empty($platforms) && !in_array(phodevi::operating_system(), $platforms))
		{
			if(phodevi::is_bsd() && in_array('Linux', $platforms) && (pts_client::executable_in_path('kldstat') && strpos(shell_exec('kldstat -n linux 2>&1'), 'linux.ko') != false))
			{
				// The OS is BSD but there is Linux API/ABI compatibility support loaded
				$supported = true;
			}
			else if(phodevi::is_hurd() && in_array('Linux', $platforms) && in_array('BSD', $platforms))
			{
				// For now until test profiles explicity express Hurd support, just list as supported the tests that work on both BSD and Linux
				// TODO: fill in Hurd support for test profiles / see what works
				$supported = true;
			}
			else
			{
				$supported = false;
			}
		}

		return $supported;
	}
	public static function generate_comparison_hash($test_identifier, $arguments, $attributes = null, $version = null, $raw_output = true)
	{
		$hash_table = array(
		$test_identifier,
		trim($arguments),
		trim($attributes),
		$version
		);

		return sha1(implode(',', $hash_table), $raw_output);
	}
	public function get_test_executable_dir()
	{
		$to_execute = null;
		$test_dir = $this->get_install_dir();
		$execute_binary = $this->get_test_executable();

		if(is_executable($test_dir . $execute_binary) || (phodevi::is_windows() && is_file($test_dir . $execute_binary)))
		{
			$to_execute = $test_dir;
		}

		return $to_execute;
	}
	public function is_test_installed()
	{
		return is_file($this->get_install_dir() . 'pts-install.xml');
	}
	public function get_install_dir()
	{
		return pts_client::test_install_root_path() . $this->identifier . '/';
	}
	public function get_installer_checksum()
	{
		return $this->get_file_installer() != false ? md5_file($this->get_file_installer()) : false;
	}
	public function get_file_installer()
	{
		$test_resources_location = $this->get_resource_dir();
		$os_postfix = '_' . strtolower(phodevi::operating_system());

		if(is_file($test_resources_location . 'install' . $os_postfix . '.sh'))
		{
			$installer = $test_resources_location . 'install' . $os_postfix . '.sh';
		}
		else if(is_file($test_resources_location . 'install.sh'))
		{
			$installer = $test_resources_location . 'install.sh';
		}
		else
		{
			$installer = null;
		}

		return $installer;
	}
	public function get_file_download_spec()
	{
		return is_file($this->get_resource_dir() . 'downloads.xml') ? $this->get_resource_dir() . 'downloads.xml' : false;
	}
	public function get_file_parser_spec()
	{
		return is_file($this->get_resource_dir() . 'results-definition.xml') ? $this->get_resource_dir() . 'results-definition.xml' : false;
	}
	public function extended_test_profiles()
	{
		// Provide an array containing the location(s) of all test(s) for the supplied object name
		$test_profiles = array();

		foreach(array_unique(array_reverse($this->get_test_extensions_recursive())) as $extended_test)
		{
			$test_profile = new pts_test_profile($extended_test);
			array_push($test_profiles, $test_profile);
		}

		return $test_profiles;
	}
	public function needs_updated_install()
	{
		// Checks if test needs updating
		// || $this->test_installation->get_installed_system_identifier() != phodevi::system_id_string()
		return $this->test_installation == false || $this->get_test_profile_version() != $this->test_installation->get_installed_version() || $this->get_installer_checksum() != $this->test_installation->get_installed_checksum() || (pts_c::$test_flags & pts_c::force_install);
	}
	public function to_json()
	{
		$file = $this->xml_parser->getFileLocation();

		if(is_file($file))
		{
			$file = file_get_contents($file);
			$file = str_replace(array("\n", "\r", "\t"), '', $file);
			$file = trim(str_replace('"', "'", $file));
			$simple_xml = simplexml_load_string($file);
			return json_encode($simple_xml);
		}
	}
}

?>
