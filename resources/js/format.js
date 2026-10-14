/**
 * The API sends UTC "YYYY-MM-DD HH:MM:SS"; show it in local time.
 */
export function formatDate(value) {
  if (!value) {
    return '—'
  }
  const date = new Date(value.replace(' ', 'T') + 'Z')
  return isNaN(date)
    ? value
    : date.toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      })
}
