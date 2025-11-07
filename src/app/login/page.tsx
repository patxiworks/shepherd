
'use client';

import * as React from 'react';
import Image from 'next/image';
import {useRouter} from 'next/navigation';
import {useForm} from 'react-hook-form';
import {zodResolver} from '@hookform/resolvers/zod';
import * as z from 'zod';
import {Button} from '@/components/ui/button';
import {Input} from '@/components/ui/input';
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {useToast} from '@/hooks/use-toast';
import {Loader2, LogIn, Download} from 'lucide-react';
import {Card, CardContent, CardHeader, CardTitle, CardDescription, CardFooter} from '@/components/ui/card';

const loginFormSchema = z.object({
  zone: z.string().min(1, {message: 'Please select a zone.'}),
  passcode: z.string().min(1, {message: 'Passcode is required.'}),
});

type LoginFormValues = z.infer<typeof loginFormSchema>;

interface BeforeInstallPromptEvent extends Event {
  readonly platforms: Array<string>;
  readonly userChoice: Promise<{
    outcome: 'accepted' | 'dismissed';
    platform: string;
  }>;
  prompt(): Promise<void>;
}


export default function LoginPage() {
  const [zones, setZones] = React.useState<string[]>([]);
  const [isLoading, setIsLoading] = React.useState(false);
  const [installPromptEvent, setInstallPromptEvent] = React.useState<BeforeInstallPromptEvent | null>(null);
  const router = useRouter();
  const {toast} = useToast();

  const form = useForm<LoginFormValues>({
    resolver: zodResolver(loginFormSchema),
    defaultValues: {
      zone: '',
      passcode: '',
    },
  });

  React.useEffect(() => {
    // Handle PWA installation prompt
    const handleBeforeInstallPrompt = (event: Event) => {
      event.preventDefault();
      setInstallPromptEvent(event as BeforeInstallPromptEvent);
    };

    window.addEventListener('beforeinstallprompt', handleBeforeInstallPrompt);

    return () => {
      window.removeEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
    };
  }, []);

  React.useEffect(() => {
    // Redirect if already logged in
    if (typeof window !== 'undefined' && window.localStorage) {
      const storedData = localStorage.getItem('pastoresData');
      if (storedData) {
        try {
          const parsedData = JSON.parse(storedData);
          if (parsedData && parsedData.activities && parsedData.activities.length > 0) {
            router.push('/');
            return; // Stop further execution in this effect
          }
        } catch (e) {
          // If parsing fails, it's safe to assume login is needed.
          console.warn("Could not parse cached data, proceeding to login.", e);
        }
      }
    }

    const fetchZones = async () => {
      try {
        const response = await fetch('/api/auth/zone-login');
        if (!response.ok) throw new Error('Failed to fetch zones');
        const data = await response.json();
        setZones(data.zones || []);
      } catch (error) {
        toast({
          title: 'Error',
          description: 'Could not load zones for login.',
          variant: 'destructive',
        });
      }
    };
    fetchZones();
  }, [router, toast]);

  const handleLoginSubmit = async (values: LoginFormValues) => {
    setIsLoading(true);
    try {
      const response = await fetch('/api/auth/zone-login', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(values),
      });

      const data = await response.json();

      if (!response.ok || !data.success) {
        throw new Error(data.message || 'Login failed.');
      }

      // On success, save user data to localStorage and redirect
      if (typeof window !== 'undefined' && window.localStorage) {
        localStorage.setItem('zoneUser', JSON.stringify(data.user));
        // Clear old data to ensure fresh fetch on new login
        localStorage.removeItem('pastoresData');
        sessionStorage.removeItem('scrolledToToday');
      }
      
      toast({
        title: 'Login Successful',
        //description: `Welcome, ${data.user.name}!`,
      });

      router.push('/');

    } catch (error) {
      toast({
        title: 'Login Failed',
        description: (error as Error).message,
        variant: 'destructive',
      });
    } finally {
      setIsLoading(false);
    }
  };

  const handleInstallClick = () => {
    if (installPromptEvent) {
      installPromptEvent.prompt();
    }
  };

  return (
    <div className="flex min-h-screen flex-col items-center justify-center bg-primary/20 px-4">
      <Image
        src="/pastores.scale-400.png"
        alt="Pastores Logo"
        width={70}
        height={70}
        className="rounded-full z-10 -mb-8"
      />
      <Card className="w-full max-w-xs pt-2">
        <CardHeader className="text-center">
          <CardTitle className="text-2xl font-headline">Welcome back</CardTitle>
          <CardDescription>
            Choose a zone and enter the passcode
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Form {...form}>
            <form
              onSubmit={form.handleSubmit(handleLoginSubmit)}
              className="space-y-6"
            >
              <fieldset disabled={isLoading} className="space-y-4">
                <FormField
                  control={form.control}
                  name="zone"
                  render={({field}) => (
                    <FormItem>
                      <FormLabel>Zone</FormLabel>
                      <Select
                        onValueChange={field.onChange}
                        defaultValue={field.value}
                      >
                        <FormControl>
                          <SelectTrigger>
                            <SelectValue placeholder="Select your zone" />
                          </SelectTrigger>
                        </FormControl>
                        <SelectContent>
                          {zones.length > 0 ? (
                            zones.map(zone => (
                              <SelectItem key={zone} value={zone}>
                                {zone}
                              </SelectItem>
                            ))
                          ) : (
                            <SelectItem value="loading" disabled>
                              Loading zones...
                            </SelectItem>
                          )}
                        </SelectContent>
                      </Select>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="passcode"
                  render={({field}) => (
                    <FormItem>
                      <FormLabel>Passcode</FormLabel>
                      <FormControl>
                        <Input
                          type="password"
                          placeholder="Enter passcode"
                          {...field}
                        />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </fieldset>
              <Button type="submit" className="w-full bg-accent" disabled={isLoading}>
                {isLoading ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <LogIn className="mr-2 h-4 w-4" />
                )}
                {isLoading ? 'Signing in...' : 'Sign In'}
              </Button>
            </form>
          </Form>
        </CardContent>
        {installPromptEvent && (
            <CardFooter className="flex-col gap-2 pt-2">
                <Button variant="outline" className="w-full" onClick={handleInstallClick}>
                    <Download className="mr-2 h-4 w-4"/>
                    Install App
                </Button>
            </CardFooter>
        )}
      </Card>
    </div>
  );
}
