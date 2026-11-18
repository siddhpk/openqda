import {
  AcademicCapIcon,
  ChartPieIcon,
  DocumentTextIcon,
  LightBulbIcon,
  ShareIcon,
  TableCellsIcon,
  Square3Stack3DIcon,
  SquaresPlusIcon,
} from '@heroicons/vue/20/solid/index.js';
import { Routes } from './Routes.js';
import { UserCircleIcon } from '@heroicons/vue/20/solid';

/**
 * Single-point-of truth definition for which
 * routes to be placed in navigation menus.
 * Array order is explicit.
 */
export const NavRoutes = [
  {
    icon: Square3Stack3DIcon,
    route: Routes.project,
  },
  {
    icon: AcademicCapIcon,
    route: Routes.design,
  },
  {
    icon: SquaresPlusIcon,
    route: Routes.preparation,
  },
  {
    icon: DocumentTextIcon,
    route: Routes.coding,
  },
  {
    icon: ChartPieIcon,
    route: Routes.analysis,
  },
  {
    icon: ShareIcon,
    route: Routes.map,
  },
  {
    icon: LightBulbIcon,
    route: Routes.concepts,
  },
  {
    icon: TableCellsIcon,
    route: Routes.cases,
  },
  {
    icon: UserCircleIcon,
    route: Routes.profile,
  },
];
