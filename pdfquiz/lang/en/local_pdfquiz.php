<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'PDF Quiz AI';
$string['studentnav'] = 'AI study assistant';
$string['pdfquiz:viewstudentnavigation'] = 'Show the AI study assistant link in primary navigation';
$string['pdfquiz:use'] = 'Use PDF Quiz AI (PDF to quiz and personal history)';

// Quiz chooser modal (2 options: Standard + From PDF).
$string['quizchoose'] = 'How would you like to create this quiz?';
$string['quizchoosehelp'] = 'Choose a method. Both options create a standard Moodle Quiz in the selected course section.';
$string['quizmanual'] = 'Standard method';
$string['quizmanualhelp'] = 'Create a quiz and add questions manually, from a question bank or by import.';
$string['quizpdf'] = 'From PDF file';
$string['quizpdfhelp'] = 'Upload a new PDF file or choose an existing PDF file in this course.';

// Teacher course quiz creation.
$string['coursequiz'] = 'Create a quiz from PDF';
$string['coursequizhelp'] = 'AI extracts text/diagrams from your PDF, generates multiple-choice questions and saves them as a hidden Moodle Quiz. Keep this page open until processing completes, then review the questions before making the quiz visible.';
$string['backtocourse'] = 'Back to course';
$string['quizunavailable'] = 'Quiz creation is unavailable in this course.';
$string['unsupportedsource'] = 'Only valid PDF files (.pdf) are supported.';
$string['pdfsize'] = 'The PDF file must contain data and fit within the allowed upload limit (max {$a} MB).';
$string['invalidsource'] = 'The PDF source is missing, inaccessible, or not a local file in this course.';
$string['pdfuploadfailed'] = 'Moodle could not receive the PDF file. Check PHP/site upload limits and try again.';
$string['quizbusy'] = 'This quiz creation request is already being processed. Please try again shortly.';
$string['generatedquizmissing'] = 'The quiz created for this request has been deleted. Start a new creation request.';
$string['quizsavefailed'] = 'The generated questions could not be saved as a Moodle Quiz.';
$string['sourcechanged'] = 'The source PDF has changed. Reload the page and generate the quiz again.';
$string['serviceerror'] = 'PDF processing service error: {$a}';
$string['missingapikey'] = 'Gemini API key is required. Enter your API key or ask an administrator to configure a system key.';

// Student AI study assistant.
$string['pdf_title'] = 'PDF to quiz';
$string['pdf_desc'] = 'Upload a PDF file (or choose one from your courses) to generate a micro-learning summary and practice quiz.';
$string['open'] = 'Open';
$string['history'] = 'My history';
$string['back'] = 'Back';
$string['nojs'] = 'JavaScript is required for this page.';
$string['noaccess'] = 'You do not have permission to view this item.';
$string['noitems'] = 'You have no saved PDF quiz items yet.';

// Forms & labels.
$string['titlefield'] = 'Title';
$string['quiztitle'] = 'Quiz name';
$string['questioncount'] = 'Number of questions (1–30)';
$string['pdfsource'] = 'PDF source';
$string['uploadnewpdf'] = 'Upload a new PDF file';
$string['choosecoursepdf'] = 'Choose an existing PDF file in this course';
$string['nocoursepdfs'] = 'No PDF files were found in this course yet.';
$string['advancedoptions'] = 'Advanced options';
$string['pagefrom'] = 'From page (optional)';
$string['pageto'] = 'To page (optional)';
$string['geminimodel'] = 'Gemini model';
$string['geminiapikey'] = 'Gemini API key';
$string['apikeyplaceholder'] = 'AIzaSy...';
$string['apikeysystemconfigured'] = 'A system Gemini API key is configured by the administrator. Leave blank to use the system key.';
$string['apikeysystemonly'] = 'Using the system Gemini API key configured by the administrator.';
$string['rememberkey'] = 'Remember API key in this browser (localStorage)';
$string['generatequiz'] = 'Create quiz from PDF';
$string['generatepractice'] = 'Generate summary & quiz';
$string['processingpdf'] = 'Processing PDF (extracting text/diagrams, classifying pages and generating questions)... Please keep this tab open.';
$string['savingquiz'] = 'Saving questions to Moodle Quiz...';
$string['retrycreatequiz'] = 'Retry saving quiz to Moodle';
$string['savehistory'] = 'Save to my history';
$string['savedtohistory'] = 'Saved to your history.';
$string['savetomoodle'] = 'Save as Moodle Quiz';
$string['previewheading'] = 'Preview generated summary & questions (not saved to course yet)';
$string['refineheading'] = 'Ask AI to revise this summary & quiz';
$string['refineplaceholder'] = 'E.g., Rewrite in Vietnamese, make questions more detailed/harder, lengthen summary, add deeper explanations...';
$string['refinebutton'] = 'Revise with AI';
$string['refining'] = 'AI is revising the summary and quiz based on your request...';
$string['refinedsuccess'] = 'Updated by AI in {$a}s.';
$string['refineempty'] = 'Please enter your revision instruction for AI.';
$string['quizcreated'] = 'Hidden Moodle Quiz created successfully!';
$string['viewquiz'] = 'View quiz';
$string['editquestions'] = 'Review / edit questions';
$string['quizsettings'] = 'Quiz settings (show to students)';
$string['pages'] = 'Pages';
$string['pagescount'] = '{$a} pages';
$string['pagerange'] = 'Pages {$a->start}–{$a->end}';
$string['routingstats'] = 'Analysis: {$a->total} pages (Text: {$a->text}, Mixed: {$a->mixed}, Visual: {$a->visual}, OCR: {$a->ocr}) in {$a->time}s.';

// History / view / edit.
$string['visibility'] = 'Visibility';
$string['vis_private'] = 'Private';
$string['vis_group'] = 'Specific class (group)';
$string['vis_course'] = 'Specific course';
$string['vis_public'] = 'Public (all logged-in users)';
$string['vis_course_help'] = 'Used when visibility is "Specific course". You must be enrolled.';
$string['vis_group_help'] = 'Used when visibility is "Specific class". You must be a member of the group.';
$string['created'] = 'Created';
$string['actions'] = 'Actions';
$string['edit'] = 'Edit';
$string['delete'] = 'Delete';
$string['confirmdelete'] = 'Delete this item permanently?';
$string['deleted'] = 'Item deleted.';
$string['save'] = 'Save changes';
$string['saved'] = 'Changes saved.';
$string['saveerror'] = 'Could not save: {$a}';
$string['conflict'] = 'This item was changed elsewhere. Reload the page and try again.';
$string['summary'] = 'Summary';
$string['shortsummary'] = 'Short summary';
$string['mainpoints'] = 'Main points';
$string['mainpoints_help'] = 'One point per line.';
$string['segment'] = 'Part';
$string['segmentsummary'] = 'Part summary';
$string['question'] = 'Question';
$string['correct_help'] = 'Select the radio button next to the correct answer.';
$string['explanation'] = 'Explanation';
$string['showanswer'] = 'Show answer';
$string['answer'] = 'Answer';
$string['extractedtext'] = 'Extracted PDF text (only you can see this)';

// Admin settings & diagnostics.
$string['settingsheading'] = 'PDF Processing & AI Service Settings';
$string['settingsdesc'] = 'Configure the connection to the Process-PDF Python service, AI model defaults and PDF upload limits. <a href="{$a}">Run connection & health check</a>.';
$string['setting_serviceurl'] = 'PDF service URL';
$string['setting_serviceurl_desc'] = 'Base URL of the Process-PDF FastAPI service (e.g. http://127.0.0.1:8002).';
$string['setting_apikey'] = 'System Gemini API key';
$string['setting_apikey_desc'] = 'Optional server-side Gemini API key used by the PDF processing service. When set, teachers and students do not need to enter their own key.';
$string['setting_allowuserapikey'] = 'Allow users to enter their own API key';
$string['setting_allowuserapikey_desc'] = 'When enabled, users can provide their own Gemini API key in the form (overrides the system key for that request).';
$string['setting_model'] = 'Default Gemini model';
$string['setting_model_desc'] = 'LiteLLM model identifier used for summary, quiz and vision analysis (e.g. gemini/gemini-3.5-flash-lite or gemini/gemini-2.5-flash).';
$string['setting_maxfilesize'] = 'Max PDF file size (MB)';
$string['setting_maxfilesize_desc'] = 'Maximum allowed PDF upload size in megabytes (also capped by PHP and course limits).';
$string['setting_maxpages'] = 'Max pages per PDF request';
$string['setting_maxpages_desc'] = 'Maximum number of PDF pages processed in a single request.';
$string['setting_timeout'] = 'Service request timeout (seconds)';
$string['setting_timeout_desc'] = 'Maximum seconds PHP waits for the PDF service to extract pages and generate questions.';
$string['setting_enablevision'] = 'Enable Vision LLM for diagrams/charts';
$string['setting_enablevision_desc'] = 'Analyze MIXED and VISUAL_HEAVY PDF pages using OpenCV preprocessing and Vision LLM.';
$string['setting_enableocr'] = 'Enable Selective OCR (Tesseract)';
$string['setting_enableocr_desc'] = 'Run Tesseract OCR automatically on scanned pages that have little or no selectable text.';
$string['admincheck'] = 'PDF Service Health Check';

// Privacy API.
$string['privacy:metadata:items'] = 'Stores extracted PDF text, summaries and quizzes saved by users.';
$string['privacy:metadata:quizreq'] = 'Metadata linking a user request to a generated course Quiz. Raw PDF files and API keys are not stored.';
$string['privacy:metadata:userid'] = 'Owner user ID.';
$string['privacy:metadata:title'] = 'Title of the generated quiz or summary.';
$string['privacy:metadata:extractedtext'] = 'Text extracted from the PDF.';
$string['privacy:metadata:content'] = 'Generated summary and quiz JSON.';
$string['privacy:metadata:visibility'] = 'Sharing visibility level.';
$string['privacy:metadata:courseid'] = 'Destination course.';
$string['privacy:metadata:cmid'] = 'Generated Quiz course module ID.';
$string['privacy:metadata:sourcehash'] = 'Content hash used to detect changes to the source PDF.';
$string['privacy:metadata:timecreated'] = 'Creation timestamp.';
$string['privacy:metadata:pdfservice'] = 'The PDF file is sent transiently to the configured Process-PDF service and Gemini API to extract text/diagrams and generate questions.';
$string['privacy:metadata:pdfcontent'] = 'PDF file content sent for extraction and question generation.';

