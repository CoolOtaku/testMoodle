<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * A base class for question editing forms.
 *
 * @package    moodlecore
 * @subpackage questiontypes
 * @copyright  2006 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License
 */


defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir.'/formslib.php');


abstract class question_wizard_form extends moodleform {
    /**
     * Add all the hidden form fields used by question/question.php.
     */
    protected function add_hidden_fields() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'inpopup');
        $mform->setType('inpopup', PARAM_INT);

        $mform->addElement('hidden', 'cmid');
        $mform->setType('cmid', PARAM_INT);

        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('hidden', 'returnurl');
        $mform->setType('returnurl', PARAM_LOCALURL);

        $mform->addElement('hidden', 'mdlscrollto');
        $mform->setType('mdlscrollto', PARAM_INT);

        $mform->addElement('hidden', 'appendqnumstring');
        $mform->setType('appendqnumstring', PARAM_ALPHA);
    }
}

/**
 * Form definition base class. This defines the common fields that
 * all question types need. Question types should define their own
 * class that inherits from this one, and implements the definition_inner()
 * method.
 *
 * @copyright  2006 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License
 */
abstract class question_edit_form extends question_wizard_form {
    const DEFAULT_NUM_HINTS = 2;

    /**
     * Question object with options and answers already loaded by get_question_options
     * Be careful how you use this it is needed sometimes to set up the structure of the
     * form in definition_inner but data is always loaded into the form with set_data.
     * @var object
     */
    protected $question;

    protected $contexts;
    protected $category;
    protected $categorycontext;

    /** @var object current context */
    public $context;
    /** @var array html editor options */
    public $editoroptions;
    /** @var array options to preapre draft area */
    public $fileoptions;
    /** @var object instance of question type */
    public $instance;
    /** @var object instance of custom field */
    protected $customfieldhandler;
    /** @var bool custom field plugin enabled or disabled*/
    protected $customfieldpluginenabled = true;

    public function __construct($submiturl, $question, $category, $contexts, $formeditable = true) {
        global $DB;

        $this->question = $question;
        $this->contexts = $contexts;

        // Get the question category id.
        if (isset($question->id)) {
            $qcategory = $question->categoryobject->id ?? get_question_bank_entry($question->id)->questioncategoryid;
        } else {
            $qcategory = $question->category;
        }

        $record = $DB->get_record('question_categories',
                array('id' => $qcategory), 'contextid');
        $this->context = context::instance_by_id($record->contextid);

        $this->editoroptions = array('subdirs' => 1, 'maxfiles' => EDITOR_UNLIMITED_FILES,
                'context' => $this->context);
        $this->fileoptions = array('subdirs' => 1, 'maxfiles' => -1, 'maxbytes' => -1);

        $this->category = $category;
        $this->categorycontext = context::instance_by_id($category->contextid);

        if (!\core\plugininfo\qbank::is_plugin_enabled('qbank_customfields')) {
            $this->customfieldpluginenabled = false;
        }

        parent::__construct($submiturl, null, 'post', '', ['data-qtype' => $this->qtype()], $formeditable);
    }

    /**
     * Return default value for a given form element either from user_preferences table or $default.
     *
     * To make use of user_preferences in your qtype default settings, you need to replace
     * $mform->setDefault({elementname}, {defaultvalue}); in edit_{qtypename}_form.php with
     * $mform->setDefault({elementname}, $this->get_default_value({elementname}, {defaultvalue}));
     *
     * @param string $name the name of the form field.
     * @param mixed $default default value.
     * @return string|null default value for a given form element.
     */
    protected function get_default_value(string $name, $default): ?string {
        return question_bank::get_qtype($this->qtype())->get_default_value($name, $default);
    }

    /**
     * Build the form definition.
     *
     * This adds all the form fields that the default question type supports.
     * If your question type does not support all these fields, then you can
     * override this method and remove the ones you don't want with $mform->removeElement().
     */
    protected function definition() {
        global $DB, $PAGE;

        $mform = $this->_form;

        // Standard fields at the start of the form.
        $mform->addElement('header', 'generalheader', get_string("general", 'form'));

        if (!isset($this->question->id)) {
            if (!empty($this->question->formoptions->mustbeusable)) {
                $contexts = $this->contexts->having_add_and_use();
            } else {
                $contexts = $this->contexts->having_cap('moodle/question:add');
            }

            // Adding question.
            $mform->addElement('questioncategory', 'category', get_string('category', 'question'),
                    array('contexts' => $contexts));
        } else if (!($this->question->formoptions->canmove ||
                $this->question->formoptions->cansaveasnew)) {
            // Editing question with no permission to move from category.
            $mform->addElement('questioncategory', 'category', get_string('category', 'question'),
                    array('contexts' => array($this->categorycontext)));
            $mform->addElement('hidden', 'usecurrentcat', 1);
            $mform->setType('usecurrentcat', PARAM_BOOL);
            $mform->setConstant('usecurrentcat', 1);
        } else {
            // Editing question with permission to move from category or save as new q.
            $currentgrp = array();
            $currentgrp[0] = $mform->createElement('questioncategory', 'category',
                    get_string('categorycurrent', 'question'),
                    array('contexts' => array($this->categorycontext)));
            // Validate if the question is being duplicated.
            $beingcopied = false;
            if (isset($this->question->beingcopied)) {
                $beingcopied = $this->question->beingcopied;
            }
            if (($this->question->formoptions->canedit ||
                    $this->question->formoptions->cansaveasnew) && ($beingcopied)) {
                // Not move only form.
                $currentgrp[1] = $mform->createElement('checkbox', 'usecurrentcat', '',
                        get_string('categorycurrentuse', 'question'));
                $mform->setDefault('usecurrentcat', 1);
            }
            $currentgrp[0]->freeze();
            $currentgrp[0]->setPersistantFreeze(false);
            $mform->addGroup($currentgrp, 'currentgrp',
                    get_string('categorycurrent', 'question'), null, false);

            if (($beingcopied)) {
                $mform->addElement('questioncategory', 'categorymoveto',
                    get_string('categorymoveto', 'question'),
                    array('contexts' => array($this->categorycontext)));
                if ($this->question->formoptions->canedit ||
                    $this->question->formoptions->cansaveasnew) {
                    // Not move only form.
                    $mform->disabledIf('categorymoveto', 'usecurrentcat', 'checked');
                }
            }
        }

        if (!empty($this->question->id) && !$this->question->beingcopied) {
            // Add extra information from plugins when editing a question (e.g.: Authors, version control and usage).
            $functionname = 'edit_form_display';
            $questiondata = [];
            $plugins = get_plugin_list_with_function('qbank', $functionname);
            foreach ($plugins as $componentname => $plugin) {
                $element = new StdClass();
                $element->pluginhtml = component_callback($componentname, $functionname, [$this->question]);
                $questiondata['editelements'][] = $element;
            }
            $mform->addElement('static', 'versioninfo', get_string('versioninfo', 'qbank_editquestion'),
                $PAGE->get_renderer('qbank_editquestion')->render_question_info($questiondata));
        }

        $mform->addElement('text', 'name', get_string('questionname', 'question'),
                array('size' => 50, 'maxlength' => 255));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement('editor', 'questiontext', get_string('questiontext', 'question'),
                array('rows' => 15), $this->editoroptions);
        $mform->setType('questiontext', PARAM_RAW);
        $mform->addRule('questiontext', null, 'required', null, 'client');

        $mform->addElement('select', 'status', get_string('status', 'qbank_editquestion'),
                            \qbank_editquestion\editquestion_helper::get_question_status_list());

        $mform->addElement('float', 'defaultmark', get_string('defaultmark', 'question'),
                array('size' => 7));
        $mform->setDefault('defaultmark', $this->get_default_value('defaultmark', 1));
        $mform->addRule('defaultmark', null, 'required', null, 'client');

        $mform->addElement('editor', 'generalfeedback', get_string('generalfeedback', 'question'),
                array('rows' => 10), $this->editoroptions);
        $mform->setType('generalfeedback', PARAM_RAW);
        $mform->addHelpButton('generalfeedback', 'generalfeedback', 'question');

        $mform->addElement('text', 'idnumber', get_string('idnumber', 'question'), 'maxlength="100"  size="10"');
        $mform->addHelpButton('idnumber', 'idnumber', 'question');
        $mform->setType('idnumber', PARAM_RAW);

        // Any questiontype specific fields.
        $this->definition_inner($mform);

        if (core_tag_tag::is_enabled('core_question', 'question')
            && \core\plugininfo\qbank::is_plugin_enabled('qbank_tagquestion')) {
            $this->add_tag_fields($mform);
        }

        if ($this->customfieldpluginenabled) {
            // Add custom fields to the form.
            $this->customfieldhandler = qbank_customfields\customfield\question_handler::create();
            $this->customfieldhandler->set_parent_context($this->categorycontext); // For question handler only.
            $this->customfieldhandler->instance_form_definition($mform, empty($this->question->id) ? 0 : $this->question->id);
        }

        $this->add_hidden_fields();

        $mform->addElement('hidden', 'qtype');
        $mform->setType('qtype', PARAM_ALPHA);

        $mform->addElement('hidden', 'makecopy');
        $mform->setType('makecopy', PARAM_INT);

        $buttonarray = array();
        $buttonarray[] = $mform->createElement('submit', 'updatebutton',
                get_string('savechangesandcontinueediting', 'question'));
        if ($this->can_preview()) {
            if (\core\plugininfo\qbank::is_plugin_enabled('qbank_previewquestion')) {
                $previewlink = $PAGE->get_renderer('qbank_previewquestion')->question_preview_link(
                        $this->question->id, $this->context, true);
                $buttonarray[] = $mform->createElement('static', 'previewlink', '', $previewlink);
            }
        }

        $mform->addGroup($buttonarray, 'updatebuttonar', '', array(' '), false);
        $mform->closeHeaderBefore('updatebuttonar');

        $this->add_action_buttons(true, get_string('savechanges'));

        if ((!empty($this->question->id)) && (!($this->question->formoptions->canedit ||
                        $this->question->formoptions->cansaveasnew))) {
            $mform->hardFreezeAllVisibleExcept(array('categorymoveto', 'buttonar', 'currentgrp'));
        }
    }

    /**
     * Add any question-type specific form fields.
     *
     * @param object $mform the form being built.
     */
    protected function definition_inner($mform) {
        // By default, do nothing.
    }

    /**
     * Tweak the form with values provided by custom fields in use.
     */
    public function definition_after_data() {
        $mform = $this->_form;
        if ($this->customfieldpluginenabled) {
            $this->customfieldhandler->instance_form_definition_after_data($mform,
                empty($this->question->id) ? 0 : $this->question->id);
        }
    }

    /**
     * Is the question being edited in a state where it can be previewed?
     * @return bool whether to show the preview link.
     */
    protected function can_preview() {
        return empty($this->question->beingcopied) && !empty($this->question->id) &&
                $this->question->formoptions->canedit;
    }

    /**
     * Get the list of form elements to repeat, one for each answer.
     * @param object $mform the form being built.
     * @param $label the label to use for each option.
     * @param $gradeoptions the possible grades for each answer.
     * @param $repeatedoptions reference to array of repeated options to fill
     * @param $answersoption reference to return the name of $question->options
     *      field holding an array of answers
     * @return array of form fields.
     */
    protected function get_per_answer_fields($mform, $label, $gradeoptions,
            &$repeatedoptions, &$answersoption) {
        $repeated = array();
        $answeroptions = array();
        $answeroptions[] = $mform->createElement('text', 'answer',
                $label, array('size' => 40));
        $answeroptions[] = $mform->createElement('select', 'fraction',
                get_string('gradenoun'), $gradeoptions);
        $repeated[] = $mform->createElement('group', 'answeroptions',
                $label, $answeroptions, null, false);
        $repeated[] = $mform->createElement('editor', 'feedback',
                get_string('feedback', 'question'), array('rows' => 5), $this->editoroptions);
        $repeatedoptions['answer']['type'] = PARAM_RAW;
        $repeatedoptions['fraction']['default'] = 0;
        $answersoption = 'answers';
        return $repeated;
    }

    /**
     * Add the tag and course tag fields to the mform.
     *
     * If the form is being built in a course context then add the field
     * for course tags.
     *
     * If the question category doesn't belong to a course context or we
     * aren't editing in a course context then add the tags element to allow
     * tags to be added to the question category context.
     *
     * @param object $mform The form being built
     */
    protected function add_tag_fields($mform) {
        global $CFG, $DB;

        $hastagcapability = question_has_capability_on($this->question, 'tag');
        // Is the question category in a course context?
        $qcontext = $this->categorycontext;
        $qcoursecontext = $qcontext->get_course_context(false);
        $iscourseoractivityquestion = !empty($qcoursecontext);
        // Is the current context we're editing in a course context?
        $editingcontext = $this->contexts->lowest();
        $editingcoursecontext = $editingcontext->get_course_context(false);
        $iseditingcontextcourseoractivity = !empty($editingcoursecontext);

        $mform->addElement('header', 'tagsheader', get_string('tags'));
        $tags = \core_tag_tag::get_tags_by_area_in_contexts('core_question', 'question', $this->contexts->all());
        $tagstrings = [];
        foreach ($tags as $tag) {
            $tagstrings[$tag->name] = $tag->name;
        }

        $showstandard = core_tag_area::get_showstandard('core_question', 'question');
        if ($showstandard != core_tag_tag::HIDE_STANDARD) {
            $namefield = empty($CFG->keeptagnamecase) ? 'name' : 'rawname';
            $standardtags = $DB->get_records('tag',
                    array('isstandard' => 1, 'tagcollid' => core_tag_area::get_collection('core', 'question')),
                    $namefield, 'id,' . $namefield);
            foreach ($standardtags as $standardtag) {
                $tagstrings[$standardtag->$namefield] = $standardtag->$namefield;
            }
        }

        $options = [
            'tags' => true,
            'multiple' => true,
            'noselectionstring' => get_string('anytags', 'quiz'),
        ];
        $mform->addElement('autocomplete', 'tags',  get_string('tags'), $tagstrings, $options);

        if (!$hastagcapability) {
            $mform->hardFreeze('tags');
        }

        if ($iseditingcontextcourseoractivity && !$iscourseoractivityquestion) {
            // If the question is being edited in a course or activity context
            // and the question isn't a course or activity level question then
            // allow course tags to be added to the course.
            $coursetagheader = get_string('questionformtagheader', 'core_question',
                    $editingcoursecontext->get_context_name(true));
            $mform->addElement('header', 'coursetagsheader', $coursetagheader);
            $mform->addElement('autocomplete', 'coursetags',  get_string('tags'), $tagstrings, $options);

            if (!$hastagcapability) {
                $mform->hardFreeze('coursetags');
            }
        }
    }

    /**
     * Add a set of form fields, obtained from get_per_answer_fields, to the form,
     * one for each existing answer, with some blanks for some new ones.
     * @param object $mform the form being built.
     * @param $label the label to use for each option.
     * @param $gradeoptions the possible grades for each answer.
     * @param $minoptions the minimum number of answer blanks to display.
     *      Default QUESTION_NUMANS_START.
     * @param $addoptions the number of answer blanks to add. Default QUESTION_NUMANS_ADD.
     */
    protected function add_per_answer_fields(&$mform, $label, $gradeoptions,
            $minoptions = QUESTION_NUMANS_START, $addoptions = QUESTION_NUMANS_ADD) {
        $mform->addElement('header', 'answerhdr',
                get_string('answers', 'question'), '');
        $mform->setExpanded('answerhdr', 1);
        $answersoption = '';
        $repeatedoptions = array();
        $repeated = $this->get_per_answer_fields($mform, $label, $gradeoptions,
                $repeatedoptions, $answersoption);

        if (isset($this->question->options)) {
            $repeatsatstart = count($this->question->options->$answersoption);
        } else {
            $repeatsatstart = $minoptions;
        }

        $this->repeat_elements($repeated, $repeatsatstart, $repeatedoptions,
                'noanswers', 'addanswers', $addoptions,
                $this->get_more_choices_string(), true);
    }

    /**
     * Language string to use for 'Add {no} more {whatever we call answers}'.
     */
    protected function get_more_choices_string() {
        return get_string('addmorechoiceblanks', 'question');
    }

    protected function add_combined_feedback_fields($withshownumpartscorrect = false) {
        $mform = $this->_form;

        $mform->addElement('header', 'combinedfeedbackhdr',
                get_string('combinedfeedback', 'question'));

        $fields = array('correctfeedback', 'partiallycorrectfeedback', 'incorrectfeedback');
        foreach ($fields as $feedbackname) {
            $element = $mform->addElement('editor', $feedbackname,
                    get_string($feedbackname, 'question'),
                    array('rows' => 5), $this->editoroptions);
            $mform->setType($feedbackname, PARAM_RAW);
            // Using setValue() as setDefault() does not work for the editor class.
            $element->setValue(array('text' => get_string($feedbackname.'default', 'question')));

            if ($withshownumpartscorrect && $feedbackname == 'partiallycorrectfeedback') {
                $mform->addElement('advcheckbox', 'shownumcorrect', '',
                        get_string('shownumpartscorrectwhenfinished', 'question'));
                $mform->setDefault('shownumcorrect', true);
            }
        }
    }

    /**
     * Create the form elements required by one hint.
     * @param string $withclearwrong whether this quesiton type uses the 'Clear wrong' option on hints.
     * @param string $withshownumpartscorrect whether this quesiton type uses the 'Show num parts correct' option on hints.
     * @return array form field elements for one hint.
     */
    protected function get_hint_fields($withclearwrong = false, $withshownumpartscorrect = false) {
        $mform = $this->_form;

        $repeatedoptions = array();
        $repeated = array();
        $repeated[] = $mform->createElement('editor', 'hint', get_string('hintn', 'question'),
                array('rows' => 5), $this->editoroptions);
        $repeatedoptions['hint']['type'] = PARAM_RAW;

        $optionelements = array();
        if ($withclearwrong) {
            $optionelements[] = $mform->createElement('advcheckbox', 'hintclearwrong', '',
                    get_string('clearwrongparts', 'question'));
        }
        if ($withshownumpartscorrect) {
            $optionelements[] = $mform->createElement('advcheckbox', 'hintshownumcorrect', '',
                    get_string('shownumpartscorrect', 'question'));
        }

        if (count($optionelements)) {
            $repeated[] = $mform->createElement('group', 'hintoptions',
                    get_string('hintnoptions', 'question'), $optionelements, null, false);
        }

        return array($repeated, $repeatedoptions);
    }

    protected function add_interactive_settings($withclearwrong = false,
            $withshownumpartscorrect = false) {
        $mform = $this->_form;

        $mform->addElement('header', 'multitriesheader',
                get_string('settingsformultipletries', 'question'));

        $penalties = array(
            1.0000000,
            0.5000000,
            0.3333333,
            0.2500000,
            0.2000000,
            0.1000000,
            0.0000000
        );
        if (!empty($this->question->penalty) && !in_array($this->question->penalty, $penalties)) {
            $penalties[] = $this->question->penalty;
            sort($penalties);
        }
        $penaltyoptions = array();
        foreach ($penalties as $penalty) {
            $penaltyoptions["{$penalty}"] = format_float(100 * $penalty, 5, true, true) . '%';
        }
        $mform->addElement('select', 'penalty',
                get_string('penaltyforeachincorrecttry', 'question'), $penaltyoptions);
        $mform->addHelpButton('penalty', 'penaltyforeachincorrecttry', 'question');
        $mform->setDefault('penalty', $this->get_default_value('penalty',  0.3333333));

        if (isset($this->question->hints)) {
            $counthints = count($this->question->hints);
        } else {
            $counthints = 0;
        }

        if ($this->question->formoptions->repeatelements) {
            $repeatsatstart = max(self::DEFAULT_NUM_HINTS, $counthints);
        } else {
            $repeatsatstart = $counthints;
        }

        list($repeated, $repeatedoptions) = $this->get_hint_fields(
                $withclearwrong, $withshownumpartscorrect);
        $this->repeat_elements($repeated, $repeatsatstart, $repeatedoptions,
                'numhints', 'addhint', 1, get_string('addanotherhint', 'question'), true);
    }

    public function set_data($question) {
        question_bank::get_qtype($question->qtype)->set_default_options($question);

        // Prepare question text.
        $draftid = file_get_submitted_draft_itemid('questiontext');

        if (!empty($question->questiontext)) {
            $questiontext = $question->questiontext;
        } else {
            $questiontext = $this->_form->getElement('questiontext')->getValue();
            $questiontext = $questiontext['text'];
        }
        $questiontext = file_prepare_draft_area($draftid, $this->context->id,
                'question', 'questiontext', empty($question->id) ? null : (int) $question->id,
                $this->fileoptions, $questiontext);

        $question->questiontext = array();
        $question->questiontext['text'] = $questiontext;
        $question->questiontext['format'] = empty($question->questiontextformat) ?
                editors_get_preferred_format() : $question->questiontextformat;
        $question->questiontext['itemid'] = $draftid;

        // Prepare general feedback.
        $draftid = file_get_submitted_draft_itemid('generalfeedback');

        if (empty($question->generalfeedback)) {
            $generalfeedback = $this->_form->getElement('generalfeedback')->getValue();
            $question->generalfeedback = $generalfeedback['text'];
        }

        $feedback = file_prepare_draft_area($draftid, $this->context->id,
                'question', 'generalfeedback', empty($question->id) ? null : (int) $question->id,
                $this->fileoptions, $question->generalfeedback);
        $question->generalfeedback = array();
        $question->generalfeedback['text'] = $feedback;
        $question->generalfeedback['format'] = empty($question->generalfeedbackformat) ?
                editors_get_preferred_format() : $question->generalfeedbackformat;
        $question->generalfeedback['itemid'] = $draftid;

        // Remove unnecessary trailing 0s form grade fields.
        if (isset($question->defaultgrade)) {
            $question->defaultgrade = 0 + $question->defaultgrade;
        }
        if (isset($question->penalty)) {
            $question->penalty = 0 + $question->penalty;
        }

        // Set any options.
        $extraquestionfields = question_bank::get_qtype($question->qtype)->extra_question_fields();
        if (is_array($extraquestionfields) && !empty($question->options)) {
            array_shift($extraquestionfields);
            foreach ($extraquestionfields as $field) {
                if (property_exists($question->options, $field)) {
                    $question->$field = $question->options->$field;
                }
            }
        }

        // Subclass adds data_preprocessing code here.
        $question = $this->data_preprocessing($question);

        parent::set_data($question);
    }

    /**
     * Perform an preprocessing needed on the data passed to {@link set_data()}
     * before it is used to initialise the form.
     * @param object $question the data being passed to the form.
     * @return object $question the modified data.
     */
    protected function data_preprocessing($question) {
        return $question;
    }

    /**
     * Perform the necessary preprocessing for the fields added by
     * {@link add_per_answer_fields()}.
     * @param object $question the data being passed to the form.
     * @return object $question the modified data.
     */
    protected function data_preprocessing_answers($question, $withanswerfiles = false) {
        if (empty($question->options->answers)) {
            return $question;
        }

        $key = 0;
        foreach ($question->options->answers as $answer) {
            if ($withanswerfiles) {
                // Prepare the feedback editor to display files in draft area.
                $draftitemid = file_get_submitted_draft_itemid('answer['.$key.']');
                $question->answer[$key]['text'] = file_prepare_draft_area(
                    $draftitemid,                                          // Draftid.
                    $this->context->id,                                      // Context.
                    'question',                                   // Component.
                    'answer',                                       // Filarea.
                    !empty($answer->id) ? (int) $answer->id : null, // Itemid.
                    $this->fileoptions,                                   // Options.
                    $answer->answer                                      // Text.
                );
                $question->answer[$key]['itemid'] = $draftitemid;
                $question->answer[$key]['format'] = $answer->answerformat;
            } else {
                $question->answer[$key] = $answer->answer;
            }

            $question->fraction[$key] = 0 + $answer->fraction;
            $question->feedback[$key] = array();

            // Evil hack alert. Formslib can store defaults in two ways for
            // repeat elements:
            //   ->_defaultValues['fraction[0]'] and
            //   ->_defaultValues['fraction'][0].
            // The $repeatedoptions['fraction']['default'] = 0 bit above means
            // that ->_defaultValues['fraction[0]'] has already been set, but we
            // are using object notation here, so we will be setting
            // ->_defaultValues['fraction'][0]. That does not work, so we have
            // to unset ->_defaultValues['fraction[0]'].
            unset($this->_form->_defaultValues["fraction[{$key}]"]);

            // Prepare the feedback editor to display files in draft area.
            $draftitemid = file_get_submitted_draft_itemid('feedback['.$key.']');
            $question->feedback[$key]['text'] = file_prepare_draft_area(
                $draftitemid,                                           // Draftid.
                $this->context->id,                                       // Context.
                'question',                                    // Component.
                'answerfeedback',                                // Filarea.
                !empty($answer->id) ? (int) $answer->id : null,  // Itemid.
                $this->fileoptions,                                    // Options.
                $answer->feedback                                     // Text.
            );
            $question->feedback[$key]['itemid'] = $draftitemid;
            $question->feedback[$key]['format'] = $answer->feedbackformat;
            $key++;
        }

        // Now process extra answer fields.
        $extraanswerfields = question_bank::get_qtype($question->qtype)->extra_answer_fields();
        if (is_array($extraanswerfields)) {
            // Omit table name.
            array_shift($extraanswerfields);
            $question = $this->data_preprocessing_extra_answer_fields($question, $extraanswerfields);
        }

        return $question;
    }

    /**
     * Perform the necessary preprocessing for the extra answer fields.
     *
     * Questions that do something not trivial when editing extra answer fields
     * will want to override this.
     * @param object $question the data being passed to the form.
     * @param array $extraanswerfields extra answer fields (without table name).
     * @return object $question the modified data.
     */
    protected function data_preprocessing_extra_answer_fields($question, $extraanswerfields) {
        // Setting $question->$field[$key] won't work in PHP, so we need set an array of answer values to $question->$field.
        // As we may have several extra fields with data for several answers in each, we use an array of arrays.
        // Index in $extrafieldsdata is an extra answer field name, value - array of it's data for each answer.
        $extrafieldsdata = array();
        // First, prepare an array if empty arrays for each extra answer fields data.
        foreach ($extraanswerfields as $field) {
            $extrafieldsdata[$field] = array();
        }

        // Fill arrays with data from $question->options->answers.
        $key = 0;
        foreach ($question->options->answers as $answer) {
            foreach ($extraanswerfields as $field) {
                // See hack comment in {@link data_preprocessing_answers()}.
                unset($this->_form->_defaultValues["{$field}[{$key}]"]);
                $extrafieldsdata[$field][$key] = $this->data_preprocessing_extra_answer_field($answer, $field);
            }
            $key++;
        }

        // Set this data in the $question object.
        foreach ($extraanswerfields as $field) {
            $question->$field = $extrafieldsdata[$field];
        }
        return $question;
    }

    /**
     * Perfmorm preprocessing for particular extra answer field.
     *
     * Questions with non-trivial DB - form element relationship will
     * want to override this.
     * @param object $answer an answer object to get extra field from.
     * @param string $field extra answer field name.
     * @return field value to be set to the form.
     */
    protected function data_preprocessing_extra_answer_field($answer, $field) {
        return $answer->$field;
    }

    /**
     * Perform the necessary preprocessing for the fields added by
     * {@link add_combined_feedback_fields()}.
     * @param object $question the data being passed to the form.
     * @return object $question the modified data.
     */
    protected function data_preprocessing_combined_feedback($question,
            $withshownumcorrect = false) {
        if (empty($question->options)) {
            return $question;
        }

        $fields = array('correctfeedback', 'partiallycorrectfeedback', 'incorrectfeedback');
        foreach ($fields as $feedbackname) {
            $draftid = file_get_submitted_draft_itemid($feedbackname);
            $feedback = array();
            $feedback['text'] = file_prepare_draft_area(
                $draftid,                                          // Draftid.
                $this->context->id,                                           // Context.
                'question',                                        // Component.
                $feedbackname,                                              // Filarea.
                !empty($question->id) ? (int) $question->id : null,  // Itemid.
                $this->fileoptions,                                        // Options.
                $question->options->$feedbackname                         // Text.
            );
            $feedbackformat = $feedbackname . 'format';
            $feedback['format'] = $question->options->$feedbackformat;
            $feedback['itemid'] = $draftid;

            $question->$feedbackname = $feedback;
        }

        if ($withshownumcorrect) {
            $question->shownumcorrect = $question->options->shownumcorrect;
        }

        return $question;
    }

    /**
     * Perform the necessary preprocessing for the hint fields.
     *
     * @param object $question The data being passed to the form.
     * @param bool $withclearwrong Clear wrong hints.
     * @param bool $withshownumpartscorrect Show number correct.
     * @return stdClass The modified data.
     */
    protected function data_preprocessing_hints($question, $withclearwrong = false,
            $withshownumpartscorrect = false) {
        if (empty($question->hints)) {
            return $question;
        }

        $key = 0;
        foreach ($question->hints as $hint) {
            $question->hint[$key] = array();

            // Prepare feedback editor to display files in draft area.
            $draftitemid = file_get_submitted_draft_itemid('hint['.$key.']');
            $question->hint[$key]['text'] = file_prepare_draft_area(
                $draftitemid,                                      // Draftid.
                $this->context->id,                                  // Context.
                'question',                               // Component.
                'hint',                                     // Filarea.
                !empty($hint->id) ? (int) $hint->id : null, // Itemid.
                $this->fileoptions,                               // Options.
                $hint->hint                                      // Text.
            );
            $question->hint[$key]['itemid'] = $draftitemid;
            $question->hint[$key]['format'] = $hint->hintformat;
            $key++;

            if ($withclearwrong) {
                $question->hintclearwrong[] = $hint->clearwrong;
            }
            if ($withshownumpartscorrect) {
                $question->hintshownumcorrect[] = $hint->shownumcorrect;
            }
        }

        return $question;
    }

    public function validation($fromform, $files) {
        global $DB;

        $errors = parent::validation($fromform, $files);

        // Make sure that the user can edit the question.
        if (empty($fromform['makecopy']) && isset($this->question->id)
            && !$this->question->formoptions->canedit) {
            $errors['currentgrp'] = get_string('nopermissionedit', 'question');
        }

        // Category.
        if (empty($fromform['category'])) {
            // User has provided an invalid category.
            $errors['category'] = get_string('required');
        }

        // Default mark.
        if (array_key_exists('defaultmark', $fromform) && $fromform['defaultmark'] < 0) {
            $errors['defaultmark'] = get_string('defaultmarkmustbepositive', 'question');
        }

        // Can only have one idnumber per category.
        if (strpos($fromform['category'], ',') !== false) {
            list($category, $categorycontextid) = explode(',', $fromform['category']);
        } else {
            $category = $fromform['category'];
        }
        if (isset($fromform['idnumber']) && ((string) $fromform['idnumber'] !== '')) {
            if (empty($fromform['usecurrentcat']) && !empty($fromform['categorymoveto'])) {
                $categoryinfo = $fromform['categorymoveto'];
            } else {
                $categoryinfo = $fromform['category'];
            }
            list($categoryid, $notused) = explode(',', $categoryinfo);
            $conditions = 'questioncategoryid = ? AND idnumber = ?';
            $params = [$categoryid, $fromform['idnumber']];
            if (!empty($this->question->id)) {
                // Get the question bank entry id to not check the idnumber for the same bank entry.
                $sql = "SELECT DISTINCT qbe.id
                          FROM {question_versions} qv
                          JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                         WHERE qv.questionid = ?";
                $bankentry = $DB->get_record_sql($sql, ['id' => $this->question->id]);
                $conditions .= ' AND id <> ?';
                $params[] = $bankentry->id;
            }

            if ($DB->record_exists_select('question_bank_entries', $conditions, $params)) {
                $errors['idnumber'] = get_string('idnumbertaken', 'error');
            }
        }

        if ($this->customfieldpluginenabled) {
            // Add the custom field validation.
            $errors  = array_merge($errors, $this->customfieldhandler->instance_form_validation($fromform, $files));
        }
        return $errors;
    }

    /**
     * Override this in the subclass to question type name.
     * @return the question type name, should be the same as the name() method
     *      in the question type class.
     */
    abstract public function qtype();

    /**
     * Returns an array of editor options with collapsed options turned off.
     * @deprecated since 2.6
     * @return array
     */
    protected function get_non_collabsible_editor_options() {
        debugging('get_non_collabsible_editor_options() is deprecated, use $this->editoroptions instead.', DEBUG_DEVELOPER);
        return $this->editoroptions;
    }

}
