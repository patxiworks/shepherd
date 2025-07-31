
import { useState } from 'react';
import type { AccordionGroupData, GroupItem } from '@/types';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { getLaborColor } from '@/lib/section-colors';

interface GridAccordionItemContentProps {
  item: AccordionGroupData;
  groupBy: 'centre' | 'activity' | 'date';
}

export function GridAccordionItemContent({ item, groupBy }: GridAccordionItemContentProps) {
  const [hoveredRowIndex, setHoveredRowIndex] = useState<number | null>(null);

  if (!item.items || item.items.length === 0) {
    return <p className="text-muted-foreground py-4 text-center">No scheduled items.</p>;
  }

  const getHeaders = () => {
    switch(groupBy) {
      case 'centre':
        return ['Activity', 'Date', 'Time', 'Priest'];
      case 'activity':
        return ['Centre', 'Date', 'Time', 'Priest'];
      case 'date':
        return ['Activity', 'Centre', 'Time', 'Priest'];
      default:
        return [];
    }
  };

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
  
  const headers = getHeaders();
  
  return (
    <div className="px-0 py-0 md:px-0">
      <Table>
        <TableHeader>
          <TableRow>
            {headers.map(header => <TableHead key={header}>{header}</TableHead>)}
          </TableRow>
        </TableHeader>
        <TableBody>
          {item.items.map((activityItem, index) => {
            const cells = getCellData(activityItem);
            const rowColor = getLaborColor(activityItem.labor);

            return (
              <TableRow 
                key={index}
                //style={{ backgroundColor: rowColor ? `${rowColor}1A` : 'transparent' }}
                style={{
                  backgroundColor: hoveredRowIndex === index && rowColor ? `${rowColor}33` : rowColor ? `${rowColor}1A` : 'transparent',
                }}
                onMouseEnter={() => setHoveredRowIndex(index)}
                onMouseLeave={() => setHoveredRowIndex(null)}
                className="transition-colors"
              >
                <TableCell className="font-medium">{cells[0] || 'N/A'}</TableCell>
                <TableCell>{cells[1] || 'N/A'}</TableCell>
                <TableCell>{cells[2] || 'N/A'}</TableCell>
                <TableCell>{cells[3] || 'N/A'}</TableCell>
              </TableRow>
            )
          })}
        </TableBody>
      </Table>
    </div>
  );
}
