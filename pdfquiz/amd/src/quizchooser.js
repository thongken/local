/** Choose how to create a native Moodle Quiz (Standard vs From PDF file). */
define(['core/modal', 'core/modal_events', 'core/templates', 'core/str', 'core/config'],
function(Modal, ModalEvents, Templates, Str, Config) {
    return {
        open: async function(originalUrl, activityModal) {
            const original = new URL(originalUrl, window.location.href);
            const courseid = Number(original.searchParams.get('id'));
            if (!Number.isInteger(courseid) || courseid < 1) {
                window.location.href = originalUrl;
                return;
            }
            const ids = [
                'quizchoose', 'quizchoosehelp',
                'quizmanual', 'quizmanualhelp',
                'quizpdf', 'quizpdfhelp',
            ];
            const strings = await Str.get_strings(ids.map(key => ({key, component: 'local_pdfquiz'})));

            const pdfUrl = new URL(Config.wwwroot + '/local/pdfquiz/quiz.php');
            pdfUrl.searchParams.set('courseid', courseid);
            if (original.searchParams.has('sectionid')) {
                pdfUrl.searchParams.set('sectionid', original.searchParams.get('sectionid'));
            } else {
                pdfUrl.searchParams.set('section', original.searchParams.get('section') || '0');
            }
            pdfUrl.searchParams.set('beforemod', original.searchParams.get('beforemod') || '0');

            const choices = [
                {label: strings[2], description: strings[3], url: original.href},
                {label: strings[4], description: strings[5], url: pdfUrl.href},
            ];
            const body = await Templates.render('local_pdfquiz/quizchooser', {help: strings[1], choices});
            const modal = await Modal.create({title: strings[0], body, large: true, removeOnClose: true});
            // Let the original activity chooser close completely so there is only one modal/focus trap.
            activityModal.getRoot().one(ModalEvents.hidden, () => modal.show());
            activityModal.hide();
        }
    };
});

