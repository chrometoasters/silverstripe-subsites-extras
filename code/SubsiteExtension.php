<?php

/**
 * SubsiteExtension
 * - Create a default administrator group for the subsite on creation
 *
 * @author lekoala
 */
class SubsiteExtension extends DataExtension
{

    private static $db = array(
        'BaseFolder' => 'Varchar(50)',
        'Profile' => 'Varchar(50)',
        'RedirectToPrimaryDomain' => 'Boolean',
    );
    private static $admin_default_permissions = array();

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        // Create the base folder
        if (!$this->owner->BaseFolder && $this->owner->Title) {
            $filter = new FileNameFilter();
            $this->owner->BaseFolder = $filter->filter($this->owner->getTitle());
            $this->owner->BaseFolder = str_replace(' ', '', ucwords(str_replace('-', ' ', $this->owner->BaseFolder)));
        }

        // If name has changed, rename existing groups
        $changes = $this->owner->getChangedFields();
        if (isset($changes['Title']) && !empty($changes['Title']['before'])) {
            $filter = new URLSegmentFilter();
            $groupName = $this->getAdministratorGroupName($changes['Title']['before']);
            $group = self::getGroupByName($groupName);
            if ($group) {
                $group->Title = $this->getAdministratorGroupName($changes['Title']['after']);
                $group->Code = $filter->filter($group->Title);
                $group->write();
            }
            $membersGroupName = $this->getMembersGroupName($changes['Title']['before']);
            $membersGroup = self::getGroupByName($membersGroupName);
            if ($membersGroup) {
                $membersGroup->Title = $this->getMembersGroupName($changes['Title']['after']);
                $membersGroup->Code = $filter->filter($membersGroup->Title);
                $membersGroup->write();
            }
        }
    }

    /**
     * 3.1 compat layer
     * @return type
     */
    public function getPrimarySubsiteDomain()
    {
        return $this->owner->Domains()->sort('"IsPrimary" DESC')->first();
    }

    public function onAfterWrite()
    {
        parent::onAfterWrite();

        // TODO: should test if this is needed or not
        if (!$this->owner->ID) {
            return;
        }

        // Apply the subsite title to config
        $siteconfig = $this->getSiteConfig();
        if ($siteconfig) {
            if ($siteconfig->Title == _t('Subsite.SiteConfigTitle', 'Your Site Name') && $this->owner->Title) {
                $siteconfig->Title = $this->owner->Title;
                $siteconfig->write();
            }
        }

        // Make sure we have groups for this subsite
        $groupName = $this->getAdministratorGroupName();
        $group = self::getGroupByName($groupName);
        if ($groupName && !$group) {
            $group = new Group();
            $group->Title = $groupName;
            $group->AccessAllSubsites = false;
            $group->write();

            $group->Subsites()->add($this->owner);

            // Apply default permissions to this group
            $codes = array_unique(array_keys(Permission::get_codes(false)));
            $default_permissions = Config::inst()->get('SubsiteExtension', 'admin_default_permissions');
            foreach ($default_permissions as $p) {
                if (in_array($p, $codes)) {
                    $po = new Permission(array('Code' => $p));
                    $po->write();
                    $group->Permissions()->add($po);
                }
            }

            $group->write();
        }

        // Create base folder
        $folder = $this->getBaseFolderInstance();
        if ($folder) {
            $folder->SubsiteID = $this->owner->ID;
            $folder->write();
        }



//        $membersGroupName = $this->getMembersGroupName();
//        $membersGroup = self::getGroupByName($membersGroupName);
//        if ($membersGroupName && !$membersGroup) {
//            $membersGroup = new Group();
//            $membersGroup->Title = $membersGroupName;
//            $membersGroup->AccessAllSubsites = true;
//            $membersGroup->write();
//
//            $membersGroup->Subsites()->add($this->owner);
//            $membersGroup->write();
//        }
    }

    public function updateCMSFields(\FieldList $fields)
    {
        $fields->addFieldToTab('Root.Configuration', new TextField('BaseFolder', _t('SubsiteExtra.BaseFolder', 'Base folder')));
        $fields->addFieldToTab('Root.Configuration', new CheckboxField('RedirectToPrimaryDomain', _t('SubsiteExtra.RedirectToPrimaryDomain', 'Redirect to primary domain')));

        // Profiles
        $profiles = ClassInfo::subclassesFor('SubsiteProfile');
        array_shift($profiles);
        if (!empty($profiles)) {
            $profiles = array('' => '') + $profiles;
            $fields->insertAfter(new DropdownField('Profile', _t('SubsiteExtra.SubsiteProfile', 'Subsite profile'), $profiles), 'Title');
        }

        // Better gridfield
//        if (class_exists('GridFieldEditableColumns')) {
//            $DomainsGridField = GridFieldConfig::create()
//                ->addComponent(new GridFieldButtonRow('before'))
//                ->addComponent(new GridFieldTitleHeader())
//                ->addComponent($editableCols     = new GridFieldEditableColumns())
//                ->addComponent(new GridFieldDeleteAction())
//                ->addComponent($addNew           = new GridFieldAddNewInlineButton())
//            ;
//            $addNew->setTitle(_t('SubsitesExtra.ADD_NEW', "Add a new subdomain"));
//
//            $editableColsFields              = array();
//            $editableColsFields['IsPrimary'] = array(
//                'title' => _t('SubsiteExtra.IS_PRIMARY', 'Is Primary'),
//                'callback' => function ($record, $column, $grid) {
//                    $field = new CheckboxField($column);
//                    return $field;
//                }
//            );
//            $editableColsFields['Domain'] = array(
//                'title' => _t('SubsitesExtra.TITLE', "Domain"),
//                'callback' => function ($record, $column, $grid) {
//                    $field = new TextField($column);
//                    $field->setAttribute('placeholder', 'mydomain.ext');
//                    return $field;
//                }
//            );
//
//            $editableCols->setDisplayFields($editableColsFields);
//
//            $DomainsGridField = new GridField("Domains",
//                _t('Subsite.DomainsListTitle', "Domains"),
//                $this->owner->Domains(), $DomainsGridField);
//
//            if ($fields->dataFieldByName('Domains')) {
//                $fields->replaceField('Domains', $DomainsGridField);
//            }
//        }
    }

    /**
     * @param string $name
     * @return Group
     */
    public static function getGroupByName($name)
    {
        if (!$name) {
            return false;
        }
        Subsite::$disable_subsite_filter = true;
        $urlfilter = new URLSegmentFilter;
        $g = Group::get()->filter('Code', $urlfilter->filter($name))->first();
        Subsite::$disable_subsite_filter = false;
        return $g;
    }

    /**
     * Get the administrator group name based on subsite Title
     *
     * @param string $title
     * @return string
     */
    public function getAdministratorGroupName($title = null)
    {
        if ($title === null) {
            $title = $this->owner->Title;
        }
        if (!$title) {
            return;
        }
        return 'Administrators ' . $title;
    }

    /**
     * Get the members group name based on subsite Title
     *
     * @param string $title
     * @return string
     */
    public function getMembersGroupName($title = null)
    {
        if ($title === null) {
            $title = $this->owner->Title;
        }
        if (!$title) {
            return;
        }
        return 'Members ' . $title;
    }

    /**
     * Get base folder instance
     * 
     * @return Folder
     */
    public function getBaseFolderInstance()
    {
        if ($this->owner->BaseFolder) {
            $folder = Folder::find_or_make($this->owner->BaseFolder);
            return $folder;
        }
    }

    /**
     * Alias for alternateSiteConfig
     *
     * @deprecated
     * @return \SiteConfig
     */
    public function getSiteConfig()
    {
        return $this->alternateSiteConfig();
    }

    /**
     * Return a SiteConfig for this subsite
     *
     * @return \SiteConfig
     */
    public function alternateSiteConfig()
    {
        if (!$this->owner->ID) {
            return SiteConfig::current_site_config();
        }

        $state = Subsite::$disable_subsite_filter;
        Subsite::$disable_subsite_filter = true;
        $sc = DataObject::get_one('SiteConfig', '"SubsiteID" = ' . $this->owner->ID);
        Subsite::$disable_subsite_filter = $state;

        if (!$sc) {
            $sc = new SiteConfig();
            $sc->SubsiteID = $this->owner->ID;
            $sc->Title = _t('Subsite.SiteConfigTitle', 'Your Site Name');
            $sc->Tagline = _t('Subsite.SiteConfigSubtitle', 'Your tagline here');
            $sc->write();
        }

        return $sc;
    }
}
