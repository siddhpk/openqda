import List from './src/list';
import Portrait from './src/portrait';
import WordCloud from './src/cloud';
import BarChart from './src/bar';
import Matrix from './src/matrix';
import Cooccurrence from './src/cooccurrence';

/**
 * passes all default visualization plugins
 * to the given plugin api.
 */
export default function register(api) {
  [List, Portrait, WordCloud, BarChart, Matrix, Cooccurrence].forEach(
    (plugin) => api.register(plugin)
  );
}
