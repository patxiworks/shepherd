
import { useState } from 'react';
import type { AccordionGroupData, GroupItem } from '@/types';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { getLaborColor } from '@/lib/section-colors';

interface GridAccordionItemContentProps {
  item: AccordionGroupData;
  groupBy: 'centre' | 'activity' | 'date';
  mass: string;
}

export function GridAccordionItemContent({ item, groupBy, mass }: GridAccordionItemContentProps) {
  const [hoveredRowIndex, setHoveredRowIndex] = useState<number | null>(null);

  if (!item.items || item.items.length === 0) {
    return <p className="text-muted-foreground py-4 text-center">No scheduled items.</p>;
  }

  const getCellData = (activityItem: GroupItem) => {
     switch(groupBy) {
      case 'centre':
        return [activityItem.title, activityItem.date, activityItem.time, activityItem.priest];
      case 'activity':
        return [activityItem.centre, activityItem.date, activityItem.time, activityItem.priest];
       case 'date':
        return [activityItem.title, activityItem.centre, activityItem.time, activityItem.priest];
      default:
        return [];
    }
  }
  
  return (
    <div className="px-0 py-0 md:px-0">
      <Table>
        <TableHeader className='bg-white'>
          {groupBy === 'date' && mass && (
            <TableRow>
                <TableHead colSpan={2}>{mass}</TableHead>
            </TableRow>
          )}
        </TableHeader>
        <TableBody>
          {item.items.map((activityItem, index) => {
            const cells = getCellData(activityItem);
            const rowColor = getLaborColor(activityItem.labor);

            return (
              <TableRow 
                key={index}
                style={{
                  backgroundColor: 'transparent',
                }}
                onMouseEnter={() => setHoveredRowIndex(index)}
                onMouseLeave={() => setHoveredRowIndex(null)}
                className="transition-colors text-sm"
              >
                <TableCell className="font-medium"><span className="text-lg"><strong>{cells[0] || 'N/A'}</strong></span> <br/> {cells[1]} {cells[2] ? "| "+cells[2] : ''}</TableCell>
                <TableCell className="text-right text-base"><strong>{cells[3] || ''}</strong></TableCell>
              </TableRow>
            )
          })}
        </TableBody>
      </Table>
    </div>
  );
}
