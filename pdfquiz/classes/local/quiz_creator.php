<?php
namespace local_pdfquiz\local;

defined('MOODLE_INTERNAL') || die();

/** Create real Moodle questions and a hidden Quiz using Moodle's official module/question APIs. */
class quiz_creator {
    public static function create(int $requestid, string $extractedtext, string $json, int $pagecount = 1): array {
        global $CFG, $DB;
        $request = quiz_request::owned($requestid);
        $lock = \core\lock\lock_config::get_lock_factory('local_pdfquiz')->get_lock('create_' . $requestid, 30);
        if (!$lock) {
            throw new \moodle_exception('quizbusy', 'local_pdfquiz');
        }
        $transaction = null;
        try {
            // Reload after acquiring lock: a previous call may have already committed.
            $request = quiz_request::owned($requestid);
            if ($request->cmid) {
                return quiz_request::result($request);
            }
            $effectivepages = $pagecount > 0 ? $pagecount : max(1, (int)$request->pagecount);
            $content = content_validator::decode($json);
            $errors = array_merge(
                content_validator::validate_meta($request->title, $extractedtext, $effectivepages),
                content_validator::validate_content($content)
            );
            if ($errors) {
                throw new \invalid_parameter_exception('Invalid generated questions: ' . implode(', ', array_slice($errors, 0, 5)));
            }
            $questions = [];
            foreach ($content['segments'] ?? [] as $segment) {
                foreach ($segment['quiz'] ?? [] as $question) {
                    $questions[] = $question;
                }
            }
            if (count($questions) < 1 || count($questions) > content_validator::MAX_QUESTIONS) {
                throw new \invalid_parameter_exception('Invalid generated question count.');
            }
            if ($request->sourcefileid) {
                $source = pdf_source::course_file($request->courseid, $request->sourcefileid);
                if ($source->get_contenthash() !== $request->sourcehash) {
                    throw new \moodle_exception('sourcechanged', 'local_pdfquiz');
                }
            }

            require_once($CFG->dirroot . '/course/modlib.php');
            require_once($CFG->dirroot . '/mod/quiz/locallib.php');
            require_once($CFG->libdir . '/questionlib.php');
            $course = quiz_access::course($request->courseid);
            quiz_access::destination($course, $request->sectionnum, $request->beforemod);
            $transaction = $DB->start_delegated_transaction();
            $module = self::module_data($course, $request);
            $module = add_moduleinfo($module, $course);
            $context = \context_module::instance($module->coursemodule);
            require_capability('mod/quiz:manage', $context);
            require_capability('moodle/question:add', $context);
            $category = question_get_default_category($context->id, true);
            $quiz = $DB->get_record('quiz', ['id' => $module->instance], '*', MUST_EXIST);
            $quiz->cmid = $module->coursemodule;
            $qtype = \question_bank::get_qtype('multichoice');
            foreach ($questions as $index => $question) {
                $form = self::question_form($question, $category->id, $context, $index + 1);
                $saved = $qtype->save_question((object)['qtype' => 'multichoice'], $form);
                if (!empty($saved->errors) || !empty($saved->error)) {
                    throw new \moodle_exception('quizsavefailed', 'local_pdfquiz');
                }
                quiz_add_quiz_question($saved->id, $quiz, 0, 1);
            }
            $quizobj = \mod_quiz\quiz_settings::create($quiz->id);
            $quizobj->get_grade_calculator()->recompute_quiz_sumgrades();
            $request->cmid = $module->coursemodule;
            $request->questioncount = count($questions);
            $request->pagecount = $effectivepages;
            $request->timemodified = time();
            $DB->update_record(quiz_request::TABLE, $request);
            $transaction->allow_commit();
            $transaction = null;
            return quiz_request::result($request);
        } catch (\Throwable $error) {
            if ($transaction !== null) {
                $transaction->rollback($error);
            }
            throw $error;
        } finally {
            $lock->release();
        }
    }

    private static function module_data(\stdClass $course, \stdClass $request): \stdClass {
        [, , , , $data] = prepare_new_moduleinfo_data($course, 'quiz', $request->sectionnum);
        $defaults = [
            'name' => $request->title, 'intro' => '', 'introformat' => FORMAT_HTML,
            'visible' => 0, 'visibleoncoursepage' => 1, 'showdescription' => 0,
            'timeopen' => 0, 'timeclose' => 0, 'duedate' => 0, 'timelimit' => 0,
            'preferredbehaviour' => 'deferredfeedback', 'attempts' => 0, 'attemptonlast' => 0,
            'grademethod' => QUIZ_GRADEHIGHEST, 'decimalpoints' => 2, 'questiondecimalpoints' => -1,
            'questionsperpage' => 1, 'shuffleanswers' => 1, 'sumgrades' => 0, 'grade' => 10,
            'overduehandling' => 'autosubmit', 'graceperiod' => 0, 'quizpassword' => '',
            'subnet' => '', 'browsersecurity' => '', 'delay1' => 0, 'delay2' => 0,
            'showuserpicture' => 0, 'showblocks' => 0, 'navmethod' => 'free',
            'completion' => 0, 'completionunlocked' => 1, 'beforemod' => $request->beforemod ?: null,
        ];
        foreach ($defaults as $key => $value) {
            $data->$key = $value;
        }
        unset($data->introeditor);
        foreach (['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback',
                'generalfeedback', 'rightanswer', 'overallfeedback'] as $field) {
            foreach (['during', 'immediately', 'open', 'closed'] as $when) {
                $data->{$field . $when} = $when === 'during' ? (int)in_array($field, ['attempt', 'maxmarks']) : 1;
            }
        }
        return $data;
    }

    private static function question_form(array $question, int $categoryid, \context $context, int $index): \stdClass {
        $editor = static fn(string $text): array => ['text' => $text, 'format' => FORMAT_PLAIN, 'itemid' => 0];
        $form = (object)[
            'category' => (string)$categoryid, 'context' => $context,
            'name' => \core_text::substr($index . '. ' . clean_param($question['question'], PARAM_TEXT), 0, 255),
            'questiontext' => $editor($question['question']), 'generalfeedback' => $editor($question['explanation']),
            'defaultmark' => 1, 'penalty' => 0, 'single' => 1, 'shuffleanswers' => 1,
            'answernumbering' => 'abc', 'shownumcorrect' => 0, 'showstandardinstruction' => 0,
            'correctfeedback' => $editor(''), 'partiallycorrectfeedback' => $editor(''), 'incorrectfeedback' => $editor(''),
            'answer' => [], 'fraction' => [], 'feedback' => [],
        ];
        foreach ($question['options'] as $option) {
            $form->answer[] = $editor($option);
            $form->fraction[] = trim($option) === trim($question['correct_answer']) ? 1 : 0;
            $form->feedback[] = $editor('');
        }
        return $form;
    }
}

