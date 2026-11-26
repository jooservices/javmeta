// Values the API sends as display text: the app only compares them, and shows its own translated text instead.
import { t } from '../i18n/index.js';

export const PERFORMER_PHOTO_KIND = 'Diễn viên';
// the kind label of a photo as the user sees it
export const photoKindLabel = kind => (kind === PERFORMER_PHOTO_KIND ? t('updates.performerPhoto') : kind);
