<?php

use block_listusers\controllers\GradeController;

class block_listusers extends block_base {
    /**
     * @throws coding_exception
     */
    public function init(): void
    {
        $this->title = get_string('pluginname', 'block_listusers');
    }

    /**
     * @throws dml_exception
     */
    public function get_content(): ?stdClass
    {
        global $DB, $OUTPUT;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();

        if ($_POST && isset($_POST['userid']) && isset($_POST['grade'])) {
            $action = 'save_grade';
            $controller = GradeController::getInstance($action);
            $controller->execute();
        }

        $users = $DB->get_records('user', null, 'lastname ASC');
        $grades = $DB->get_records_menu('block_listusers_grades', null, '', 'userid, grade');

        $table = '<form method="POST" >';
        $table .= '<table class="block_listuser_table">';
        $table .= '<tr>';
        $table .= '<th colspan="2">Name and Email</th>';
        $table .= '<th>Grade</th>';
        $table .= '</tr>';

        foreach ($users as $user) {
            $grade = $grades[$user->id] ?? '-';

            $table .= '<tr>';
            $table .= '<td>' . $user->firstname . '</td>';
            $table .= '<td>' . $user->lastname . '</td>';
            $table .= '<td rowspan="2">';
            $table .= '<input type="hidden" name="userid" value="' . $user->id . '">';

            $table .= '<div class="number">';
	        $table .= '<button class="number-minus" type="button" onclick="this.nextElementSibling.stepDown(); this.nextElementSibling.onchange();">-</button>';
            $table .= '<input type="number" name="grade" min="1" max="10" min="0" value="' . (is_numeric($grade) ? $grade : '') . '" readonly>';
            $table .= '<button class="number-plus" type="button" onclick="this.previousElementSibling.stepUp(); this.previousElementSibling.onchange();">+</button>';
            $table .= '</div>';

            $table .= '<div class="text-center">';
            $table .= '<button type="submit" class="btn btn-primary">Save</button>';
            $table .= '</div>';

            $table .= '</td>';
            $table .= '</tr>';
            $table .= '<tr><td colspan="2">' . $user->email . '</td></tr>';
            $table .= '<tr><td colspan="3" class="separator"></td></tr>';
        }

        $table .= '</table></form>';

        $this->content->text = $table;
        return $this->content;
    }

    public function has_config() {
        return true;
    }
}
