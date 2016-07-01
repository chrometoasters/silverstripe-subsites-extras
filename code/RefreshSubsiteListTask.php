<?php

/**
 * Description of RefreshSubsiteListTask
 *
 * @author LeKoala <thomas@lekoala.be>
 */
class RefreshSubsiteListTask extends BuildTask
{

    public function run($request)
    {
        HTTP::set_cache_age(0);
        set_time_limit(0);
        $classes = SubsiteDataObjectMany::extendedClasses();

        foreach ($classes as $cl) {
            $s = singleton($cl);

            $rec = $cl::get();
            foreach ($rec as $r) {
                $oldList = $r->SubsiteList;
                $list    = $r->buildSubsiteList();
                if ($list != $oldList) {
                    $qry = "UPDATE $cl SET SubsiteList = '$list' WHERE ID = {$r->ID}";
                    DB::query($qry);
                    echo "$qry<br/>";
                }
            }
        }

        echo 'All done!';
    }
}