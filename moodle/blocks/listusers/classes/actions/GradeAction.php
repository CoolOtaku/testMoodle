<?php

namespace block_listusers\actions;

class GradeAction extends AbstractAction
{
    /**
     * @throws \dml_exception
     */
    public function execute(): bool
    {
        global $DB;

        if ($_POST && isset($_POST['userid']) && isset($_POST['grade'])) {
            $userid = (int)$_POST['userid'];
            $grade = (int)$_POST['grade'];

            if ($DB->record_exists('block_listusers_grades', ['userid' => $userid])) {
                $DB->set_field('block_listusers_grades', 'grade', $grade, ['userid' => $userid]);
            } else {
                $DB->insert_record('block_listusers_grades', ['userid' => $userid, 'grade' => $grade]);
            }

            return true;
        }

        return false;
    }
}
