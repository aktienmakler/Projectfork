<?php
/**
 * @package      Projectfork
 *
 * @author       Tobias Kuhn (eaxs)
 * @copyright    Copyright (C) 2006-2012 Tobias Kuhn. All rights reserved.
 * @license      http://www.gnu.org/licenses/gpl.html GNU/GPL, see LICENSE.txt
 */

defined('_JEXEC') or die();


jimport('joomla.application.component.modeladmin');


/**
 * Item Model for a timesheet form.
 *
 */
class ProjectforkModelTime extends JModelAdmin
{
    /**
     * The prefix to use with controller messages.
     *
     * @var    string
     */
    protected $text_prefix = 'COM_PROJECTFORK_TIME';


    /**
	 * Constructor.
	 *
	 * @param   array  $config  An optional associative array of configuration settings.
	 *
	 * @see     JController
	 */
    public function __construct($config = array())
    {
        // Register dependencies
        JLoader::register('ProjectforkHelperAccess',     JPATH_ADMINISTRATOR . '/components/com_projectfork/helpers/access.php');
        JLoader::register('ProjectforkHelperQuery',      JPATH_ADMINISTRATOR . '/components/com_projectfork/helpers/query.php');

        parent::__construct($config);
    }


    /**
     * Returns a Table object, always creating it.
     *
     * @param     string    The table type to instantiate
     * @param     string    A prefix for the table class name. Optional.
     * @param     array     Configuration array for model. Optional.
     *
     * @return    jtable    A database object
     */
    public function getTable($type = 'Time', $prefix = 'PFTable', $config = array())
    {
        return JTable::getInstance($type, $prefix, $config);
    }


    /**
     * Method to get a single record.
     *
     * @param     integer    The id of the primary key.
     * @return    mixed      Object on success, false on failure.
     */
    public function getItem($pk = null)
    {
        if ($item = parent::getItem($pk)) {
            // Convert the params field to an array.
            $registry = new JRegistry;
            $registry->loadString($item->attribs);
            $item->attribs = $registry->toArray();

            // Convert seconds back to minutes
            if ($item->log_time > 0) {
                $item->log_time = round($item->log_time / 60);
            }
        }

        return $item;
    }


    /**
     * Method to get the record form.
     *
     * @param     array      Data for the form.
     * @param     boolean    True if the form is to load its own data (default case), false if not.
     * @return    mixed      A JForm object on success, false on failure
     */
    public function getForm($data = array(), $loadData = true)
    {
        // Get the form.
        $form = $this->loadForm('com_projectfork.time', 'time', array('control' => 'jform', 'load_data' => $loadData));
        if (empty($form)) return false;

        // Check if a project id is already selected. If not, set the currently active project as value
        $project_id = (int) $form->getValue('project_id');

        if (!$this->getState($this->getName() . '.id') && $project_id == 0) {
            $active_id = ProjectforkHelper::getActiveProjectId();

            $form->setValue('project_id', null, $active_id);
        }

        return $form;
    }


    /**
     * Method to save the form data.
     *
     * @param     array      $data    The form data.
     *
     * @return    boolean             True on success, False on error.
     */
    public function save($data)
    {
        $record = $this->getTable();
        $key    = $record->getKeyName();
        $pk     = (!empty($data[$key])) ? $data[$key] : (int) $this->getState($this->getName() . '.id');
        $is_new = true;

        if ($pk > 0) {
            if ($record->load($pk)) {
                $is_new = false;
            }
        }

        // Handle permissions and access level
        if (isset($data['rules'])) {
            $access = ProjectforkHelperAccess::getViewLevelFromRules($data['rules'], intval($data['access']));

            if ($access) {
                $data['access'] = $access;
            }
        }
        else {
            if ($is_new) {
                $data['access'] = 1;
            }
            else {
                if (isset($data['access'])) {
                    unset($data['access']);
                }
            }
        }

        // Try to convert log string to time
        if (isset($data['log_time'])) {
            if (!is_numeric($data['log_time'])) {
                $log_time = strtotime($data['log_time']);

                if ($log_time === false || $log_time < 0) {
                    $data['log_time'] = 1;
                }
                else {
                    $data['log_time'] = $log_time - time();
                }
            }
            else {
                // not a literal time, so convert minutes to secs
                $data['log_time'] = $data['log_time'] * 60;
            }
        }

        if (parent::save($data)) {
            $id = $this->getState($this->getName() . '.id');

            // Load the just updated row
            $updated = $this->getTable();
            if ($updated->load($id) === false) return false;

            // Set the active project
            ProjectforkHelper::setActiveProject($updated->project_id);

            return true;
        }

        return false;
    }


    /**
     * Method to get the data that should be injected in the form.
     *
     * @return    mixed    The data for the form.
     */
    protected function loadFormData()
    {
        // Check the session for previously entered form data.
        $data = JFactory::getApplication()->getUserState('com_projectfork.edit.' . $this->getName() . '.data', array());

        if (empty($data)) $data = $this->getItem();

        return $data;
    }


    /**
     * Custom clean the cache of com_projectfork and projectfork modules
     *
     */
    protected function cleanCache($group = null, $client_id = 0)
    {
        parent::cleanCache('com_projectfork');
    }


    /**
     * Method to test whether a record can be deleted.
     * Defaults to the permission set in the component.
     *
     * @param     object     A record object.
     *
     * @return    boolean    True if allowed to delete the record.
     */
    protected function canDelete($record)
    {
        if (!empty($record->id)) {
            if ($record->state != -2) return false;

            $access = ProjectforkHelperAccess::getActions('time', $record->id);
            return $access->get('time.delete');
        }
        else {
            $access = ProjectforkHelperAccess::getActions();
            return $access->get('time.delete');
        }
    }


    /**
     * Method to test whether a record can have its state edited.
     * Defaults to the permission set in the component.
     *
     * @param     object     A record object.
     *
     * @return    boolean    True if allowed to delete the record.
     */
    protected function canEditState($record)
    {
        // Check for existing item.
        if (!empty($record->id)) {
            $access = ProjectforkHelperAccess::getActions('time', $record->id);
            return $access->get('time.edit.state');
        }
        else {
            $access = ProjectforkHelperAccess::getActions();
            return $access->get('time.edit.state');
        }
    }


    /**
     * Method to test whether a record can be edited.
     * Defaults to the permission for the component.
     *
     * @param     object     A record object.
     *
     * @return    boolean    True if allowed to edit the record.
     */
    protected function canEdit($record)
    {
        // Check for existing item.
        if (!empty($record->id)) {
            $access = ProjectforkHelperAccess::getActions('time', $record->id);
            $user   = JFactory::getUser();

            return ($access->get('time.edit') || ($access->get('time.edit.own') && $record->created_by == $user->id));
        }
        else {
            $access = ProjectforkHelperAccess::getActions();
            return $access->get('time.edit');
        }
    }
}