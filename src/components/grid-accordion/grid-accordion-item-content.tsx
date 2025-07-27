
import type { AccordionGroupData } from '@/types';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

interface GridAccordionItemContentProps {
  item: AccordionGroupData;
  groupBy: 'centre' | 'activity';
}

export function GridAccordionItemContent({ item, groupBy }: GridAccordionItemContentProps) {
  if (!item.items || item.items.length === 0) {
    return <p className="text-muted-foreground py-4 text-center">No scheduled items.</p>;
  }
  
  return (
    <div className="px-1 py-2 md:px-2">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{groupBy === 'centre' ? 'Activity' : 'Centre'}</TableHead>
            <TableHead>Day</TableHead>
            <TableHead>Time</TableHead>
            <TableHead>Priest</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {item.items.map((activityItem, index) => (
            <TableRow key={index}>
              <TableCell className="font-medium">{activityItem.title}</TableCell>
              <TableCell>{activityItem.day}</TableCell>
              <TableCell>{activityItem.time}</TableCell>
              <TableCell>{activityItem.priest || 'N/A'}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}
