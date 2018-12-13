<?php
/**
 * webtrees: online genealogy
 * Copyright (C) 2018 webtrees development team
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Fisharebest\Webtrees\Http\Controllers;

use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\GedcomCode\GedcomCodePedi;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Tree;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for edit forms and responses.
 */
class EditFamilyController extends AbstractEditController
{
    /**
     * @param Request $request
     * @param Tree    $tree
     *
     * @return Response
     */
    public function reorderChildren(Request $request, Tree $tree): Response
    {
        $xref   = $request->get('xref', '');
        $family = Family::getInstance($xref, $tree);

        $this->checkFamilyAccess($family, true);

        $title = $family->getFullName() . ' — ' . I18N::translate('Re-order children');

        return $this->viewResponse('edit/reorder-children', [
            'title'  => $title,
            'family' => $family,
        ]);
    }

    /**
     * @param Request $request
     * @param Tree    $tree
     *
     * @return Response
     */
    public function reorderChildrenAction(Request $request, Tree $tree): Response
    {
        $xref   = $request->get('xref', '');
        $order  = (array) $request->get('order', []);
        $family = Family::getInstance($xref, $tree);

        $this->checkFamilyAccess($family, true);

        $dummy_facts = ['0 @' . $family->xref() . '@ FAM'];
        $sort_facts  = [];
        $keep_facts  = [];

        // Split facts into FAMS and other
        foreach ($family->facts() as $fact) {
            if ($fact->getTag() === 'CHIL') {
                $sort_facts[$fact->id()] = $fact->gedcom();
            } else {
                $keep_facts[] = $fact->gedcom();
            }
        }

        // Sort the facts
        uksort($sort_facts, function ($x, $y) use ($order) {
            return array_search($x, $order) - array_search($y, $order);
        });

        // Merge the facts
        $gedcom = implode("\n", array_merge($dummy_facts, $sort_facts, $keep_facts));

        $family->updateRecord($gedcom, false);

        return new RedirectResponse($family->url());
    }

    /**
     * @param Request $request
     *
     * @param Tree    $tree
     *
     * @return Response
     */
    public function addChild(Request $request, Tree $tree): Response
    {
        $xref   = $request->get('xref', '');
        $gender = $request->get('gender', 'U');

        $family = Family::getInstance($xref, $tree);

        $this->checkFamilyAccess($family, true);

        $title = $family->getFullName() . ' - ' . I18N::translate('Add a child');

        return $this->viewResponse('edit/new-individual', [
            'tree'       => $tree,
            'title'      => $title,
            'nextaction' => 'add_child_to_family_action',
            'individual' => null,
            'family'     => $family,
            'name_fact'  => null,
            'famtag'     => 'CHIL',
            'gender'     => $gender,
        ]);
    }

    /**
     * @param Request $request
     * @param Tree    $tree
     *
     * @return RedirectResponse
     */
    public function addChildAction(Request $request, Tree $tree): RedirectResponse
    {
        $xref = $request->get('xref', '');

        $family = Family::getInstance($xref, $tree);

        $this->checkFamilyAccess($family, true);

        $PEDI      = $request->get('PEDI', '');
        $keep_chan = (bool) $request->get('keep_chan');

        $this->glevels = $request->get('glevels', []);
        $this->tag     = $request->get('tag', []);
        $this->text    = $request->get('text', []);
        $this->islink  = $request->get('islink', []);

        $this->splitSource();
        $gedrec = '0 @@ INDI';
        $gedrec .= $this->addNewName($request, $tree);
        $gedrec .= $this->addNewSex($request);
        if (preg_match_all('/([A-Z0-9_]+)/', $tree->getPreference('QUICK_REQUIRED_FACTS'), $matches)) {
            foreach ($matches[1] as $match) {
                $gedrec .= $this->addNewFact($request, $tree, $match);
            }
        }
        $gedrec .= "\n" . GedcomCodePedi::createNewFamcPedi($PEDI, $xref);
        if ((bool) $request->get('SOUR_INDI')) {
            $gedrec = $this->handleUpdates($gedrec);
        } else {
            $gedrec = $this->updateRest($gedrec);
        }

        // Create the new child
        $new_child = $tree->createIndividual($gedrec);

        // Insert new child at the right place
        $done = false;
        foreach ($family->facts(['CHIL']) as $fact) {
            $old_child = $fact->target();
            if ($old_child  instanceof Individual && Date::compare($new_child->getEstimatedBirthDate(), $old_child->getEstimatedBirthDate()) < 0) {
                // Insert before this child
                $family->updateFact($fact->id(), '1 CHIL @' . $new_child->xref() . "@\n" . $fact->gedcom(), !$keep_chan);
                $done = true;
                break;
            }
        }
        if (!$done) {
            // Append child at end
            $family->createFact('1 CHIL @' . $new_child->xref() . '@', !$keep_chan);
        }

        if ($request->get('goto') === 'new') {
            return new RedirectResponse($new_child->url());
        }

        return new RedirectResponse($family->url());
    }

    /**
     * @param Request $request
     * @param Tree    $tree
     *
     * @return Response
     */
    public function addSpouse(Request $request, Tree $tree): Response
    {
        $xref   = $request->get('xref', '');
        $famtag = $request->get('famtag', '');

        $family = Family::getInstance($xref, $tree);

        $this->checkFamilyAccess($family, true);

        if ($famtag === 'WIFE') {
            $title  = I18N::translate('Add a wife');
            $gender = 'F';
        } else {
            $title  = I18N::translate('Add a husband');
            $gender = 'M';
        }

        return $this->viewResponse('edit/new-individual', [
            'tree'       => $tree,
            'title'      => $title,
            'nextaction' => 'add_spouse_to_family_action',
            'individual' => null,
            'family'     => $family,
            'name_fact'  => null,
            'famtag'     => $famtag,
            'gender'     => $gender,
        ]);
    }

    /**
     * @param Request $request
     * @param Tree    $tree
     *
     * @return RedirectResponse
     */
    public function addSpouseAction(Request $request, Tree $tree): RedirectResponse
    {
        $xref = $request->get('xref', '');

        $family = Family::getInstance($xref, $tree);

        $this->checkFamilyAccess($family, true);

        $this->glevels = $request->get('glevels', []);
        $this->tag     = $request->get('tag', []);
        $this->text    = $request->get('text', []);
        $this->islink  = $request->get('islink', []);

        // Create the new spouse
        $this->splitSource(); // separate SOUR record from the rest

        $gedrec = '0 @@ INDI';
        $gedrec .= $this->addNewName($request, $tree);
        $gedrec .= $this->addNewSex($request);
        if (preg_match_all('/([A-Z0-9_]+)/', $tree->getPreference('QUICK_REQUIRED_FACTS'), $matches)) {
            foreach ($matches[1] as $match) {
                $gedrec .= $this->addNewFact($request, $tree, $match);
            }
        }

        if ((bool) $request->get('SOUR_INDI')) {
            $gedrec = $this->handleUpdates($gedrec);
        } else {
            $gedrec = $this->updateRest($gedrec);
        }
        $gedrec .= "\n1 FAMS @" . $family->xref() . '@';
        $spouse = $tree->createIndividual($gedrec);

        // Update the existing family - add marriage, etc
        if ($family->getFirstFact('HUSB')) {
            $family->createFact('1 WIFE @' . $spouse->xref() . '@', true);
        } else {
            $family->createFact('1 HUSB @' . $spouse->xref() . '@', true);
        }
        $famrec = '';
        if (preg_match_all('/([A-Z0-9_]+)/', $tree->getPreference('QUICK_REQUIRED_FAMFACTS'), $matches)) {
            foreach ($matches[1] as $match) {
                $famrec .= $this->addNewFact($request, $tree, $match);
            }
        }
        if ((bool) $request->get('SOUR_FAM')) {
            $famrec = $this->handleUpdates($famrec);
        } else {
            $famrec = $this->updateRest($famrec);
        }
        $family->createFact(trim($famrec), true); // trim leading \n

        if ($request->get('goto') === 'new') {
            return new RedirectResponse($spouse->url());
        }

        return new RedirectResponse($family->url());
    }

    /**
     * @param Request $request
     * @param Tree    $tree
     *
     * @return Response
     */
    public function changeFamilyMembers(Request $request, Tree $tree): Response
    {
        $xref   = $request->get('xref', '');
        $family = Family::getInstance($xref, $tree);
        $this->checkFamilyAccess($family, true);

        $title = I18N::translate('Change family members') . ' – ' . $family->getFullName();

        return $this->viewResponse('edit/change-family-members', [
            'tree'     => $tree,
            'title'    => $title,
            'family'   => $family,
            'father'   => $family->getHusband(),
            'mother'   => $family->getWife(),
            'children' => $family->getChildren(),
        ]);
    }

    /**
     * @param Request $request
     * @param Tree    $tree
     *
     * @return RedirectResponse
     */
    public function changeFamilyMembersAction(Request $request, Tree $tree): RedirectResponse
    {
        $xref   = $request->get('xref', '');
        $family = Family::getInstance($xref, $tree);
        $this->checkFamilyAccess($family, true);

        $HUSB = $request->get('HUSB', '');
        $WIFE = $request->get('WIFE', '');
        $CHIL = $request->get('CHIL', []);

        // Current family members
        $old_father   = $family->getHusband();
        $old_mother   = $family->getWife();
        $old_children = $family->getChildren();

        // New family members
        $new_father   = Individual::getInstance($HUSB, $tree);
        $new_mother   = Individual::getInstance($WIFE, $tree);
        $new_children = [];
        foreach ($CHIL as $child) {
            $new_children[] = Individual::getInstance($child, $tree);
        }

        if ($old_father !== $new_father) {
            if ($old_father) {
                // Remove old FAMS link
                foreach ($old_father->facts(['FAMS']) as $fact) {
                    if ($fact->target() === $family) {
                        $old_father->deleteFact($fact->id(), true);
                    }
                }
                // Remove old HUSB link
                foreach ($family->facts(['HUSB', 'WIFE']) as $fact) {
                    if ($fact->target() === $old_father) {
                        $family->deleteFact($fact->id(), true);
                    }
                }
            }
            if ($new_father) {
                // Add new FAMS link
                $new_father->createFact('1 FAMS @' . $family->xref() . '@', true);
                // Add new HUSB link
                $family->createFact('1 HUSB @' . $new_father->xref() . '@', true);
            }
        }

        if ($old_mother !== $new_mother) {
            if ($old_mother) {
                // Remove old FAMS link
                foreach ($old_mother->facts(['FAMS']) as $fact) {
                    if ($fact->target() === $family) {
                        $old_mother->deleteFact($fact->id(), true);
                    }
                }
                // Remove old WIFE link
                foreach ($family->facts(['HUSB', 'WIFE']) as $fact) {
                    if ($fact->target() === $old_mother) {
                        $family->deleteFact($fact->id(), true);
                    }
                }
            }
            if ($new_mother) {
                // Add new FAMS link
                $new_mother->createFact('1 FAMS @' . $family->xref() . '@', true);
                // Add new WIFE link
                $family->createFact('1 WIFE @' . $new_mother->xref() . '@', true);
            }
        }

        foreach ($old_children as $old_child) {
            if ($old_child && !in_array($old_child, $new_children)) {
                // Remove old FAMC link
                foreach ($old_child->facts(['FAMC']) as $fact) {
                    if ($fact->target() === $family) {
                        $old_child->deleteFact($fact->id(), true);
                    }
                }
                // Remove old CHIL link
                foreach ($family->facts(['CHIL']) as $fact) {
                    if ($fact->target() === $old_child) {
                        $family->deleteFact($fact->id(), true);
                    }
                }
            }
        }

        foreach ($new_children as $new_child) {
            if ($new_child && !in_array($new_child, $old_children)) {
                // Add new FAMC link
                $new_child->createFact('1 FAMC @' . $family->xref() . '@', true);
                // Add new CHIL link
                $family->createFact('1 CHIL @' . $new_child->xref() . '@', true);
            }
        }

        return new RedirectResponse($family->url());
    }
}