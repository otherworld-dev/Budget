import { describe, it, expect } from 'vitest';
import { parentPickerTree } from '../../src/modules/categories/parentPicker.js';

// As /api/categories/tree sends it: own categories, then shared ones.
const rawTree = [
    { id: 1, name: 'Groceries', children: [{ id: 2, name: 'Snacks', children: [] }] },
    {
        id: 10, name: 'Groceries', _shared: true, _sharedBy: 'bob', _sharedByName: 'Bob', _canManage: true,
        children: [{ id: 11, name: 'Fruit', _shared: true, _sharedBy: 'bob', _canManage: true, children: [] }],
    },
    {
        id: 20, name: 'Home', _shared: true, _sharedBy: 'carol', _canWrite: true, _canManage: false,
        children: [{ id: 21, name: 'Garden', _shared: true, _sharedBy: 'carol', _canManage: true, children: [] }],
    },
];

const ids = (tree) => tree.flatMap(node => [node.id, ...ids(node.children || [])]);

describe('parentPickerTree', () => {
    it('offers only your own categories when editing one of yours', () => {
        expect(ids(parentPickerTree(rawTree, 'own'))).toEqual([1, 2]);
    });

    it('adds Full control categories, named with their owner, for a new category', () => {
        const tree = parentPickerTree(rawTree, 'add');
        expect(ids(tree)).toEqual([1, 2, 10, 11, 21]);
        expect(tree[1].name).toBe('Groceries · Bob');
    });

    it('lifts a Full control child out from under a parent you cannot build under', () => {
        const tree = parentPickerTree(rawTree, 'add');
        expect(tree.map(node => node.id)).toContain(21);
    });

    it('keeps an owner scope to that owner\'s Full control categories, unlabelled', () => {
        const tree = parentPickerTree(rawTree, { owner: 'bob' });
        expect(ids(tree)).toEqual([10, 11]);
        expect(tree[0].name).toBe('Groceries');
    });

    it('copes with no tree yet', () => {
        expect(parentPickerTree(undefined, 'add')).toEqual([]);
    });
});
