/**
 * Entry. Deliberately tiny: Joomla appends ?version to this file's URL, so the real app and the lazy module pages live
 * in hashed chunks that import each other by identical URLs. If the app code were in this entry, a lazy page chunk would
 * import it under a different URL (without the query) and the browser would run a second copy of React.
 */
void import( './bootstrap' );
